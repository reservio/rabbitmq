<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use Nette;
use PhpAmqpLib\Channel\AMQPChannel;



abstract class AmqpMember
{

	use Nette\SmartObject;

	/**
	 * @var Connection
	 */
	protected $connection;

	/**
	 * @var AMQPChannel
	 */
	protected $channel;

	/**
	 * @var bool
	 */
	protected $autoSetupFabric = TRUE;



	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
	}



	public function __destruct()
	{
		if ($this->channel !== NULL) {
			$this->channel->close();
		}

		if ($this->connection->isConnected()) {
			$this->connection->close();
		}
	}



	public function getChannel() : AMQPChannel
	{
		if ($this->channel === NULL) {
			$this->channel = $this->connection->channel();
		}

		return $this->channel;
	}



	public function setChannel(AMQPChannel $channel) : void
	{
		$this->channel = $channel;
	}



	public function disableAutoSetupFabric() : void
	{
		$this->autoSetupFabric = FALSE;
	}

}
