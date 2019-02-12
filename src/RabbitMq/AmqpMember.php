<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use Nette\SmartObject;
use PhpAmqpLib\Channel\AMQPChannel;



abstract class AmqpMember
{

	use SmartObject;

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @var AMQPChannel
	 */
	private $channel;



	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
	}



	public function __destruct()
	{
		if ($this->channel !== NULL) {
			$this->channel->close();
		}
	}



	public function getChannel() : AMQPChannel
	{
		if ($this->channel === NULL) {
			$this->channel = $this->connection->channel();
		}

		return $this->channel;
	}



	/**
	 * @internal
	 * @param AMQPChannel $channel
	 */
	public function setChannel(AMQPChannel $channel) : void
	{
		$this->channel = $channel;
	}

}
