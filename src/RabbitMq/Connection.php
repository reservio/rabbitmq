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
	 * @var string[]
	 */
	private $consumersMap = [];

	/**
	 * @var string[]
	 */
	private $producersMap = [];



	public function getConsumer(string $name) : Consumer
	{
		if (!isset($this->consumersMap[$name])) {
			throw new \InvalidArgumentException("Unknown consumer {$name}");
		}

		/** @var Consumer $consumer */
		$consumer = $this->serviceLocator->getService($this->consumersMap[$name]);

		return $consumer;
	}



	public function getProducer(string $name) : Producer
	{
		if (!isset($this->producersMap[$name])) {
			throw new \InvalidArgumentException("Unknown producer {$name}");
		}

		/** @var Producer $producer */
		$producer = $this->serviceLocator->getService($this->producersMap[$name]);

		return $producer;
	}



	/**
	 * @internal
	 * @param Container $serviceLocator
	 */
	public function injectServiceLocator(Container $serviceLocator) : void
	{
		$this->serviceLocator = $serviceLocator;
	}



	/**
	 * @internal
	 * @param string[] $consumers
	 */
	public function injectConsumersMap(array $consumers) : void
	{
		$this->consumersMap = $consumers;
	}



	/**
	 * @internal
	 * @param string[] $producers
	 */
	public function injectProducersMap(array $producers) : void
	{
		$this->producersMap = $producers;
	}

}
