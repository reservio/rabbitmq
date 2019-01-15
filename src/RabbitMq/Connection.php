<?php

namespace Damejidlo\RabbitMq;

use Damejidlo;
use Nette;
use PhpAmqpLib;



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class Connection extends PhpAmqpLib\Connection\AMQPLazyConnection implements IConnection
{

	/**
	 * @var Nette\DI\Container
	 */
	private $serviceLocator;

	/**
	 * @var array
	 */
	private $serviceMap = [];



	/**
	 * @param string $name
	 * @return BaseConsumer
	 */
	public function getConsumer($name)
	{
		if (!isset($this->serviceMap['consumer'][$name])) {
			throw new InvalidArgumentException("Unknown consumer {$name}");
		}

		return $this->serviceLocator->getService($this->serviceMap['consumer'][$name]);
	}



	/**
	 * @param $name
	 * @return Producer
	 */
	public function getProducer($name)
	{
		if (!isset($this->serviceMap['producer'][$name])) {
			throw new InvalidArgumentException("Unknown producer {$name}");
		}

		return $this->serviceLocator->getService($this->serviceMap['producer'][$name]);
	}



	/**
	 * @internal
	 */
	public function injectServiceMap(array $producers, array $consumers)
	{
		$this->serviceMap = [
			'consumer' => $consumers,
			'producer' => $producers,
		];
	}



	/**
	 * @internal
	 * @param Nette\DI\Container $sl
	 */
	public function injectServiceLocator(Nette\DI\Container $sl)
	{
		$this->serviceLocator = $sl;
	}



	/**
	 * Fetch a Channel object identified by the numeric channel_id, or
	 * create that object if it doesn't already exist.
	 *
	 * @param string $id
	 * @return Channel
	 */
	public function channel($id = null)
	{
		if (isset($this->channels[$id])) {
			return $this->channels[$id];
		}

		$this->connect();
		$id = $id ? $id : $this->get_free_channel_id();

		return $this->channels[$id] = $this->doCreateChannel($id);
	}



	protected function doCreateChannel(string $id) : Channel
	{
		$channel = new Channel($this->connection, $id);

		return $channel;
	}

}
