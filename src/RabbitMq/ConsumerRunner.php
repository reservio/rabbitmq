<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use Nette\SmartObject;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;



/**
 * @method void onStart(ConsumerRunner $runner)
 * @method void onStop(ConsumerRunner $runner)
 * @method void onTimeout(ConsumerRunner $runner)
 * @method void onError(ConsumerRunner $runner, AMQPExceptionInterface $exception)
 */
class ConsumerRunner extends AmqpMember
{

	use SmartObject;

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
	public $onTimeout = [];

	/**
	 * @var callable[]
	 */
	public $onError = [];

	/**
	 * @var Consumer[]
	 */
	private $consumers = [];

	/**
	 * @var int|NULL
	 */
	private $memoryLimit;

	/**
	 * @var int
	 */
	private $idleTimeout = 0;

	/**
	 * @var int
	 */
	private $target;

	/**
	 * @var int
	 */
	private $consumed = 0;

	/**
	 * @var bool
	 */
	private $forceStop = FALSE;



	public function addConsumer(Consumer $consumer) : void
	{
		$this->consumers[] = $consumer;
		$consumer->setChannel($this->getChannel());
		$consumer->onMessageProcessed[] = function () : void {
			$this->onMessageProcessed();
		};
	}



	/**
	 * @return string[]
	 */
	public function getQueueNames() : array
	{
		return array_map(
			function (Consumer $consumer) : string {
				return $consumer->getQueueName();
			},
			$this->consumers
		);
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



	public function consume(int $messageAmount) : void
	{
		$this->target = $messageAmount;
		$this->setupConsumers();
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



	public function stopConsuming() : void
	{
		foreach ($this->consumers as $consumer) {
			$consumer->stopConsuming();
		}
		$this->onStop($this);
	}



	public function forceStopConsumer() : void
	{
		$this->forceStop = TRUE;
	}



	private function setupConsumers() : void
	{
		foreach ($this->consumers as $consumer) {
			$consumer->setupConsumer();
		}
	}



	private function onMessageProcessed() : void
	{
		$this->consumed++;
		$this->maybeStopConsumer();

		if ($this->isRamAlmostOverloaded()) {
			$this->stopConsuming();
		}
	}



	private function maybeStopConsumer() : void
	{
		if (extension_loaded('pcntl') && (defined('AMQP_WITHOUT_SIGNALS') ? !AMQP_WITHOUT_SIGNALS : TRUE)) {
			if (!function_exists('pcntl_signal_dispatch')) {
				throw new \BadFunctionCallException("Function 'pcntl_signal_dispatch' is referenced in the php.ini 'disable_functions' and can't be called.");
			}

			pcntl_signal_dispatch();
		}

		if ($this->forceStop || $this->isMessageLimitReached()) {
			$this->stopConsuming();
		}
	}



	private function isMessageLimitReached() : bool
	{
		if ($this->target <= 0) {
			return FALSE;
		}

		return $this->consumed >= $this->target;
	}



	private function isRamAlmostOverloaded() : bool
	{
		if ($this->getMemoryLimit() === NULL) {
			return FALSE;
		}

		return memory_get_usage(TRUE) >= ($this->getMemoryLimit() * 1024 * 1024);
	}

}
