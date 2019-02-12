<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;



/**
 * @method void onStart(Consumer $self)
 * @method void onStop(Consumer $self)
 * @method void onConsume(Consumer $self, AMQPMessage $msg)
 * @method void onReject(Consumer $self, AMQPMessage $msg, int $processFlag)
 * @method void onAck(Consumer $self, AMQPMessage $msg)
 * @method void onError(Consumer $self, AMQPExceptionInterface $exception)
 * @method void onTimeout(Consumer $self)
 */
class Consumer extends AmqpMember
{

	/**
	 * @var callable[]
	 */
	public $onStart = [];

	/**
	 * @var callable[]
	 */
	public $onStop = [];

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
	public $onTimeout = [];

	/**
	 * @var callable[]
	 */
	public $onError = [];

	/**
	 * @var string
	 */
	protected $consumerTag;

	/**
	 * @var mixed[]
	 */
	protected $qosOptions = [
		'prefetchSize' => 0,
		'prefetchCount' => 0,
	];

	/**
	 * @var callable
	 */
	protected $callback;

	/**
	 * @var int|NULL
	 */
	protected $memoryLimit;

	/**
	 * @var int
	 */
	protected $idleTimeout = 0;

	/**
	 * @var int
	 */
	protected $target;

	/**
	 * @var int
	 */
	protected $consumed = 0;

	/**
	 * @var bool
	 */
	protected $forceStop = FALSE;

	/**
	 * @var bool
	 */
	protected $qosDeclared = FALSE;



	public function __construct(Connection $connection, string $consumerTag = '')
	{
		parent::__construct($connection);
		$this->consumerTag = $consumerTag === '' ? sprintf("PHPPROCESS_%s_%s", gethostname(), getmypid()) : $consumerTag;
	}



	public function setQosOptions(int $prefetchSize = 0, int $prefetchCount = 0) : void
	{
		$this->qosOptions = [
			'prefetchSize' => $prefetchSize,
			'prefetchCount' => $prefetchCount,
		];
	}



	public function setCallback(callable $callback) : void
	{
		$this->callback = $callback;
	}



	public function setMemoryLimit(?int $memoryLimit) : void
	{
		$this->memoryLimit = $memoryLimit;
	}



	public function getMemoryLimit() : ?int
	{
		return $this->memoryLimit;
	}



	public function setIdleTimeout(int $seconds) : void
	{
		$this->idleTimeout = $seconds;
	}



	public function getIdleTimeout() : int
	{
		return $this->idleTimeout;
	}



	public function getConsumerTag() : string
	{
		return $this->consumerTag;
	}



	public function consume(int $messageAmount) : void
	{
		$this->target = $messageAmount;
		$this->setupConsumer();
		$this->onStart($this);

		$previousErrorHandler = set_error_handler(function ($severity, $message, $file, $line, $context) use (&$previousErrorHandler) {
			if (preg_match('~stream_select\\(\\)~i', $message)) {
				throw new AMQPRuntimeException($message . ' in ' . $file . ':' . $line, (int) $severity);
			}

			if (!is_callable($previousErrorHandler)) {
				return FALSE;
			}

			return call_user_func_array($previousErrorHandler, func_get_args());
		});

		try {
			while (count($this->getChannel()->callbacks)) {
				$this->maybeStopConsumer();

				try {
					$this->getChannel()->wait(NULL, FALSE, $this->getIdleTimeout());
				} catch (AMQPTimeoutException $exception) {
					$this->onTimeout($this);
					// nothing bad happened, right?
					// intentionally not throwing the exception
				}
			}

		} catch (AMQPRuntimeException $exception) {
			restore_error_handler();

			// sending kill signal to the consumer causes the stream_select to return FALSE
			// the reader doesn't like the FALSE value, so it throws AMQPRuntimeException
			$this->maybeStopConsumer();
			if (!$this->forceStop) {
				$this->onError($this, $exception);
				throw $exception;
			}

		} catch (AMQPExceptionInterface $exception) {
			restore_error_handler();

			$this->onError($this, $exception);
			throw $exception;

		} catch (TerminateException $exception) {
			$this->stopConsuming();
		}
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
		$this->onStop($this);
	}



	public function forceStopConsumer() : void
	{
		$this->forceStop = TRUE;
	}



	public function purge() : void
	{
		$this->getChannel()->queue_purge($this->queueOptions['name'], TRUE);
	}



	protected function setupConsumer() : void
	{
		if ($this->autoSetupFabric) {
			$this->setupFabric();
		}

		if (!$this->qosDeclared) {
			$this->qosDeclare();
		}

		$this->getChannel()->basic_consume(
			$this->queueOptions['name'],
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



	protected function maybeStopConsumer() : void
	{
		if (extension_loaded('pcntl') && (defined('AMQP_WITHOUT_SIGNALS') ? !AMQP_WITHOUT_SIGNALS : TRUE)) {
			if (!function_exists('pcntl_signal_dispatch')) {
				throw new \BadFunctionCallException("Function 'pcntl_signal_dispatch' is referenced in the php.ini 'disable_functions' and can't be called.");
			}

			pcntl_signal_dispatch();
		}

		if ($this->forceStop || ($this->consumed == $this->target && $this->target > 0)) {
			$this->stopConsuming();
		}
	}



	protected function qosDeclare() : void
	{
		if (!array_filter($this->qosOptions)) {
			return;
		}

		$this->getChannel()->basic_qos(
			$this->qosOptions['prefetchSize'],
			$this->qosOptions['prefetchCount'],
			FALSE
		);

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

		$this->consumed++;
		$this->maybeStopConsumer();

		if ($this->isRamAlmostOverloaded()) {
			$this->stopConsuming();
		}
	}



	protected function isRamAlmostOverloaded() : bool
	{
		if ($this->getMemoryLimit() === NULL) {
			return FALSE;
		}

		return memory_get_usage(TRUE) >= ($this->getMemoryLimit() * 1024 * 1024);
	}

}
