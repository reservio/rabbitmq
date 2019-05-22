<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

class QueueNotFoundException extends \RuntimeException
{

}



class TerminateException extends \RuntimeException
{

	/**
	 * @var int
	 */
	private $response = IConsumer::MSG_REJECT_REQUEUE;



	public static function withResponse(int $response) : self
	{
		$e = new self();
		$e->response = $response;
		return $e;
	}



	public function getResponse() : int
	{
		return $this->response;
	}

}



class FailedToPublishMessageException extends \RuntimeException
{

	public static function withExchange(string $exchange) : self
	{
		return new static("Failed to publish message to exchange '$exchange'.");
	}

}



class UnroutableMessageException extends FailedToPublishMessageException
{

	public static function withExchangeAndRoutingKey(string $exchange, string $routingKey) : self
	{
		return new static("Exchange '$exchange' could not route message with routing key '$routingKey' to any queue.");
	}

}
