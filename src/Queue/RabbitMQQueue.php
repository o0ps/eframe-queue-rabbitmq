<?php

namespace EFrame\QueueRabbitMQ\Queue;

use RuntimeException;
use Illuminate\Queue\Queue;
use Interop\Amqp\AmqpTopic;
use Interop\Amqp\AmqpQueue;
use Psr\Log\LoggerInterface;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\Impl\AmqpBind;
use EFrame\QueueRabbitMQ\Queue\Jobs\RabbitMQJob;
use Illuminate\Contracts\Queue\Queue as QueueContract;

/**
 * Class RabbitMQQueue
 * @package EFrame\QueueRabbitMQ\Queue
 */
class RabbitMQQueue extends Queue implements QueueContract
{
    /**
     * @var int|mixed
     */
    protected $sleepOnError;

    /**
     * @var mixed
     */
    protected $queueName;

    /**
     * @var array
     */
    protected $queueOptions;

    /**
     * @var array
     */
    protected $exchangeOptions;

    /**
     * @var array
     */
    private $declaredExchanges = [];

    /**
     * @var array
     */
    private $declaredQueues    = [];

    /**
     * @var AmqpContext
     */
    private $context;

    /**
     * @var string
     */
    private $correlationId;

    /**
     * RabbitMQQueue constructor.
     *
     * @param AmqpContext $context
     * @param array       $config
     */
    public function __construct(AmqpContext $context, array $config)
    {
        $this->context = $context;

        $this->queueName                 = $config['queue'] ?? $config['options']['queue']['name'];
        $this->queueOptions              = $config['options']['queue'];
        $this->queueOptions['arguments'] = isset($this->queueOptions['arguments']) ?
            json_decode($this->queueOptions['arguments'], true) : [];

        $this->exchangeOptions              = $config['options']['exchange'];
        $this->exchangeOptions['arguments'] = isset($this->exchangeOptions['arguments']) ?
            json_decode($this->exchangeOptions['arguments'], true) : [];

        $this->sleepOnError = $config['sleep_on_error'] ?? 5;
    }

    /**
     * @param null $queueName
     *
     * @return int
     */
    public function size($queueName = null): int
    {
        /** @var AmqpQueue $queue */
        list($queue) = $this->declareEverything($queueName);

        return $this->context->declareQueue($queue);
    }

    /**
     * @param        $job
     * @param string $data
     * @param null   $queue
     *
     * @return null
     * @throws \Exception
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, []);
    }

    /**
     * @param       $payload
     * @param null  $queueName
     * @param array $options
     *
     * @return null
     * @throws \Exception
     */
    public function pushRaw($payload, $queueName = null, array $options = [])
    {
        try {
            /**
             * @var AmqpTopic $topic
             * @var AmqpQueue $queue
             */
            list($queue, $topic) = $this->declareEverything($queueName);

            $message = $this->context->createMessage($payload);
            $message->setRoutingKey($queue->getQueueName());
            $message->setCorrelationId($this->getCorrelationId());
            $message->setContentType('application/json');
            $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);

            if (isset($options['headers'])) {
                $message->setHeaders($options['headers']);
            }

            if (isset($options['properties'])) {
                $message->setProperties($options['properties']);
            }

            if (isset($options['attempts'])) {
                $message->setProperty(RabbitMQJob::ATTEMPT_COUNT_HEADERS_KEY, $options['attempts']);
            }

            $producer = $this->context->createProducer();
            if (isset($options['delay']) && $options['delay'] > 0) {
                $producer->setDeliveryDelay($options['delay'] * 1000);
            }

            $producer->send($topic, $message);

            return $message->getCorrelationId();
        } catch (\Exception $exception) {
            $this->reportConnectionError('pushRaw', $exception);

            return null;
        }
    }

    /**
     * @param        $delay
     * @param        $job
     * @param string $data
     * @param null   $queue
     *
     * @return null
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, ['delay' => $this->secondsUntil($delay)]);
    }

    /**
     * Release a reserved job back onto the queue.
     *
     * @param  \DateTimeInterface|\DateInterval|int $delay
     * @param  string|object                        $job
     * @param  mixed                                $data
     * @param  string                               $queue
     * @param  int                                  $attempts
     *
     * @return mixed
     */
    public function release($delay, $job, $data, $queue, $attempts = 0)
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, [
            'delay'    => $this->secondsUntil($delay),
            'attempts' => $attempts,
        ]);
    }

    /** @inheritdoc */
    public function pop($queueName = null)
    {
        try {
            /** @var AmqpQueue $queue */
            list($queue) = $this->declareEverything($queueName);

            $consumer = $this->context->createConsumer($queue);

            if ($message = $consumer->receiveNoWait()) {
                return new RabbitMQJob($this->container, $this, $consumer, $message);
            }
        } catch (\Exception $exception) {
            $this->reportConnectionError('pop', $exception);
        }

        return null;
    }

    /**
     * Retrieves the correlation id, or a unique id.
     *
     * @return string
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId ?: uniqid('', true);
    }

    /**
     * Sets the correlation id for a message to be published.
     *
     * @param string $id
     *
     * @return void
     */
    public function setCorrelationId(string $id)
    {
        $this->correlationId = $id;
    }

    /**
     * @return AmqpContext
     */
    public function getContext(): AmqpContext
    {
        return $this->context;
    }

    /**
     * @param string $queueName
     *
     * @return array [Interop\Amqp\AmqpQueue, Interop\Amqp\AmqpTopic]
     */
    private function declareEverything(string $queueName = null): array
    {
        $queueName    = $queueName ?: $this->queueName;
        $exchangeName = $this->exchangeOptions['name'] ?: $queueName;

        $topic = $this->context->createTopic($exchangeName);
        $topic->setType($this->exchangeOptions['type']);
        $topic->setArguments($this->exchangeOptions['arguments']);
        if ($this->exchangeOptions['passive']) {
            $topic->addFlag(AmqpTopic::FLAG_PASSIVE);
        }
        if ($this->exchangeOptions['durable']) {
            $topic->addFlag(AmqpTopic::FLAG_DURABLE);
        }
        if ($this->exchangeOptions['auto_delete']) {
            $topic->addFlag(AmqpTopic::FLAG_AUTODELETE);
        }

        if ($this->exchangeOptions['declare'] && !in_array($exchangeName, $this->declaredExchanges, true)) {
            $this->context->declareTopic($topic);

            $this->declaredExchanges[] = $exchangeName;
        }

        $queue = $this->context->createQueue($queueName);
        $queue->setArguments($this->queueOptions['arguments']);
        if ($this->queueOptions['passive']) {
            $queue->addFlag(AmqpQueue::FLAG_PASSIVE);
        }
        if ($this->queueOptions['durable']) {
            $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        }
        if ($this->queueOptions['exclusive']) {
            $queue->addFlag(AmqpQueue::FLAG_EXCLUSIVE);
        }
        if ($this->queueOptions['auto_delete']) {
            $queue->addFlag(AmqpQueue::FLAG_AUTODELETE);
        }

        if ($this->queueOptions['declare'] && !in_array($queueName, $this->declaredQueues, true)) {
            $this->context->declareQueue($queue);

            $this->declaredQueues[] = $queueName;
        }

        if ($this->queueOptions['bind']) {
            $this->context->bind(new AmqpBind($queue, $topic, $queue->getQueueName()));
        }

        return [$queue, $topic];
    }

    /**
     * @param string     $action
     * @param \Exception $e
     *
     * @throws \Exception
     */
    protected function reportConnectionError($action, \Exception $e)
    {
        /** @var LoggerInterface $logger */
        $logger = $this->container['log'];

        $logger->error('AMQP error while attempting ' . $action . ': ' . $e->getMessage());

        // If it's set to false, throw an error rather than waiting
        if ($this->sleepOnError === false) {
            throw new RuntimeException('Error writing data to the connection with RabbitMQ', null, $e);
        }

        // Sleep so that we don't flood the log file
        sleep($this->sleepOnError);
    }
}
