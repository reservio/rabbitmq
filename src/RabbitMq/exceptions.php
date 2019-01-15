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
