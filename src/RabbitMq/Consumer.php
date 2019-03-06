<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;



/**
 * @method void onConsume(Consumer $self, AMQPMessage $msg)
 * @method void onReject(Consumer $self, AMQPMessage $msg, int $processFlag)
 * @method void onAck(Consumer $self, AMQPMessage $msg)
 * @method void onMessageProcessed(Consumer $self, AMQPMessage $msg)
 */
class Consumer extends AmqpMember
{

	public const DEFAULT_QUEUE_OPTIONS = [
		'passive' => FALSE,
		'durable' => TRUE,
		'exclusive' => FALSE,
		'autoDelete' => FALSE,
		'nowait' => FALSE,
		'arguments' => NULL,
		'ticket' => NULL,
	];

	/**
	 * @var callable[]
	 */
	public $onConsume = [];

	/**
	 * @var callable[]
	 */
	public $onReject = [];

	/**
	 * @var callable[]
	 */
	public $onAck = [];

	/**
	 * @var callable[]
	 */
	public $onMessageProcessed = [];

	/**
	 * @var string
	 */
	protected $queueName;

	/**
	 * @var callable
	 */
	protected $callback;

	/**
	 * @var mixed[]
	 */
	protected $queueOptions;

	/**
	 * @var int
	 */
	protected $prefetchSize;

	/**
	 * @var int
	 */
	protected $prefetchCount;

	/**
	 * @var string
	 */
	protected $consumerTag;

	/**
	 * @var bool
	 */
	protected $qosDeclared = FALSE;

	/**
	 * @var bool
	 */
	protected $queueDeclared = FALSE;

	/**
	 * @var string[][]
	 */
	protected $bindings = [];

	/**
	 * @var bool
	 */
	private $autoSetupFabric = TRUE;



	/**
	 * @param Connection $connection
	 * @param string $queueName
	 * @param callable $callback
	 * @param mixed[] $queueOptions
	 * @param int $prefetchSize
	 * @param int $prefetchCount
	 * @param string $consumerTag
	 */
	public function __construct(
		Connection $connection,
		string $queueName,
		callable $callback,
		array $queueOptions = [],
		int $prefetchSize = 0,
		int $prefetchCount = 0,
		string $consumerTag = ''
	) {
		parent::__construct($connection);
		$this->queueName = $queueName;
		$this->callback = $callback;
		$this->queueOptions = $queueOptions + self::DEFAULT_QUEUE_OPTIONS;
		$this->prefetchSize = $prefetchSize;
		$this->prefetchCount = $prefetchCount;
		$this->consumerTag = $consumerTag === '' ? sprintf("PHPPROCESS_%s_%s_%s", gethostname(), getmypid(), $queueName) : $consumerTag;
	}



	public function addBinding(string $exchange, string $routingKey = '') : void
	{
		$this->bindings[$exchange][] = $routingKey;
	}



	public function disableAutoSetupFabric() : void
	{
		$this->autoSetupFabric = FALSE;
	}



	public function isAutoSetupFabric() : bool
	{
		return $this->autoSetupFabric;
	}



	public function setupFabric() : void
	{
		if ($this->queueDeclared) {
			return;
		}

		$this->getChannel()->queue_declare(
			$this->queueName,
			$this->queueOptions['passive'],
			$this->queueOptions['durable'],
			$this->queueOptions['exclusive'],
			$this->queueOptions['autoDelete'],
			$this->queueOptions['nowait'],
			$this->queueOptions['arguments'],
			$this->queueOptions['ticket']
		);

		foreach ($this->bindings as $exchangeName => $routingKeys) {
			foreach ($routingKeys as $routingKey) {
				$this->getChannel()->queue_bind($this->queueName, $exchangeName, $routingKey);
			}
		}
		$this->queueDeclared = TRUE;
	}



	public function getQueueName() : string
	{
		return $this->queueName;
	}



	public function getConsumerTag() : string
	{
		return $this->consumerTag;
	}



	public function processMessage(AMQPMessage $message) : void
	{
		$this->onConsume($this, $message);
		try {
			$processFlag = call_user_func($this->callback, $message);
			$this->handleProcessMessage($message, $processFlag);

		} catch (TerminateException $exception) {
			$this->handleProcessMessage($message, $exception->getResponse());
			throw $exception;

		} catch (\Throwable $exception) {
			$this->onReject($this, $message, IConsumer::MSG_REJECT_REQUEUE);
			throw $exception;
		}
	}



	public function stopConsuming() : void
	{
		$this->getChannel()->basic_cancel($this->getConsumerTag());
	}



	public function purge() : void
	{
		$this->getChannel()->queue_purge($this->queueName, TRUE);
	}



	public function setupConsumer() : void
	{
		if ($this->isAutoSetupFabric()) {
			$this->setupFabric();
		}

		$this->qosDeclare();

		$this->getChannel()->basic_consume(
			$this->queueName,
			$this->getConsumerTag(),
			$this->queueOptions['noLocal'],
			$this->queueOptions['noAck'],
			$this->queueOptions['exclusive'],
			$this->queueOptions['nowait'],
			function (AMQPMessage $message) : void {
				$this->processMessage($message);
			}
		);
	}



	protected function qosDeclare() : void
	{
		if ($this->qosDeclared) {
			return;
		}

		if ($this->prefetchSize !== 0 || $this->prefetchCount !== 0) {
			$this->getChannel()->basic_qos(
				$this->prefetchSize,
				$this->prefetchCount,
				FALSE
			);
		}

		$this->qosDeclared = TRUE;
	}



	protected function handleProcessMessage(AMQPMessage $message, int $processFlag) : void
	{
		if ($processFlag === IConsumer::MSG_ACK) {
			// Remove message from queue
			$message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
			$this->onAck($this, $message);

		} elseif ($processFlag === IConsumer::MSG_REJECT) {
			// Reject and drop
			$message->delivery_info['channel']->basic_reject($message->delivery_info['delivery_tag'], FALSE);
			$this->onReject($this, $message, $processFlag);

		} elseif ($processFlag === IConsumer::MSG_REJECT_REQUEUE) {
			// Reject and requeue message to RabbitMQ
			$message->delivery_info['channel']->basic_reject($message->delivery_info['delivery_tag'], TRUE);
			$this->onReject($this, $message, $processFlag);

		} elseif ($processFlag === IConsumer::MSG_SINGLE_NACK_REQUEUE) {
			// NACK and requeue message to RabbitMQ
			$message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag'], FALSE, TRUE);
			$this->onReject($this, $message, $processFlag);

		} else {
			throw new \InvalidArgumentException("Invalid response flag '$processFlag'.");
		}

		$this->onMessageProcessed($this, $message);
	}

}
