<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;



class MultipleConsumer extends Consumer
{

	/**
	 * @var mixed[]
	 */
	protected $queues = [];



	public function getQueueConsumerTag(string $queue) : string
	{
		return sprintf('%s-%s', $this->getConsumerTag(), $queue);
	}



	/**
	 * @param mixed[] $queues
	 */
	public function setQueues(array $queues) : void
	{
		$this->queues = [];
		foreach ($queues as $name => $queue) {
			if (!isset($queue['callback'])) {
				throw new \InvalidArgumentException("The queue '$name' is missing a callback.");
			}

			if (!is_callable($queue['callback'])) {
				throw new \InvalidArgumentException("The callback of queue '$name' is not a valid callback.");
			}

			$this->queues[$name] = $queue;
		}
	}



	/**
	 * @return mixed[]
	 */
	public function getQueues() : array
	{
		return $this->queues;
	}



	public function processQueueMessage(string $queueName, AMQPMessage $message) : void
	{
		if (!isset($this->queues[$queueName])) {
			throw new QueueNotFoundException();
		}

		$this->onConsume($this, $message);
		try {
			$processFlag = call_user_func($this->queues[$queueName]['callback'], $message);
			$this->handleProcessMessage($message, $processFlag);

		} catch (TerminateException $exception) {
			$this->handleProcessMessage($message, $exception->getResponse());
			throw $exception;

		} catch (\Throwable $exception) {
			$this->onReject($this, $message, IConsumer::MSG_REJECT_REQUEUE);
			throw $exception;
		}
	}



	protected function setupConsumer() : void
	{
		if ($this->autoSetupFabric) {
			$this->setupFabric();
		}

		if (!$this->qosDeclared) {
			$this->qosDeclare();
		}

		foreach ($this->queues as $name => $options) {
			$this->getChannel()->basic_consume(
				$name,
				$this->getQueueConsumerTag($name),
				FALSE,
				FALSE,
				FALSE,
				FALSE,
				function (AMQPMessage $message) use ($name) : void {
					$this->processQueueMessage($name, $message);
				}
			);
		}
	}



	protected function queueDeclare() : void
	{
		foreach ($this->queues as $name => $options) {
			$this->doQueueDeclare($name, $options);
		}

		$this->queueDeclared = TRUE;
	}

}
