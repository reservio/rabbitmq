<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use Nette\DI\Container;
use PhpAmqpLib\Connection\AMQPLazyConnection;



class Connection extends AMQPLazyConnection
{

	/**
	 * @var Container
	 */
	private $serviceLocator;

	/**
	 * @var string[][]
	 */
	private $serviceMap = [];



	public function getConsumer(string $name) : Consumer
	{
		if (!isset($this->serviceMap['consumer'][$name])) {
			throw new \InvalidArgumentException("Unknown consumer {$name}");
		}

		/** @var Consumer $consumer */
		$consumer = $this->serviceLocator->getService($this->serviceMap['consumer'][$name]);

		return $consumer;
	}



	public function getProducer(string $name) : IProducer
	{
		if (!isset($this->serviceMap['producer'][$name])) {
			throw new \InvalidArgumentException("Unknown producer {$name}");
		}

		/** @var IProducer $producer */
		$producer = $this->serviceLocator->getService($this->serviceMap['producer'][$name]);

		return $producer;
	}



	/**
	 * @internal
	 * @param string[] $producers
	 * @param string[] $consumers
	 */
	public function injectServiceMap(array $producers, array $consumers) : void
	{
		$this->serviceMap = [
			'consumer' => $consumers,
			'producer' => $producers,
		];
	}



	/**
	 * @internal
	 * @param Container $serviceLocator
	 */
	public function injectServiceLocator(Container $serviceLocator) : void
	{
		$this->serviceLocator = $serviceLocator;
	}

}
