<?php
declare(strict_types = 1);

namespace DamejidloTests\RabbitMq\DI;

use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use Damejidlo\RabbitMq\MultipleConsumer;
use Damejidlo\RabbitMq\Producer;
use DamejidloTests\DjTestCase;
use Nette\Configurator;
use Nette\DI\Container;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tester\Assert;

require_once __DIR__ . '/../../bootstrap.php';



/**
 * @testCase
 */
class RabbitMqExtensionTest extends DjTestCase
{

	public function testServices() : void
	{
		$container = $this->createContainer();

		// foo was defined first in config
		Assert::type(AMQPStreamConnection::class, $container->getByType(Connection::class));
		Assert::same($container->getByType(Connection::class), $container->getService('rabbitmq.foo_connection.connection'));

		// only the first defined connection is autowired
		Assert::type(AMQPStreamConnection::class, $container->getService('rabbitmq.default.connection'));
		Assert::notSame($container->getByType(Connection::class), $container->getService('rabbitmq.default.connection'));

		Assert::type(Producer::class, $container->getService('rabbitmq.producer.foo_producer'));
		Assert::type(Producer::class, $container->getService('rabbitmq.producer.default_producer'));

		Assert::type(Consumer::class, $container->getService('rabbitmq.consumer.foo_consumer'));
		Assert::type(Consumer::class, $container->getService('rabbitmq.consumer.default_consumer'));
		Assert::type(Consumer::class, $container->getService('rabbitmq.consumer.qos_test_consumer'));
		Assert::type(MultipleConsumer::class, $container->getService('rabbitmq.consumer.multi_test_consumer'));
	}



	public function testExtendingConsumerFromProducer() : void
	{
		$container = $this->createContainer();

		/** @var Consumer $defaultConsumer */
		$defaultConsumer = $container->getService('rabbitmq.consumer.default_consumer');
		Assert::same('default_exchange', $defaultConsumer->getExchangeOptions()['name']);
	}



	private function createContainer() : Container
	{
		$config = new Configurator();
		$config->setTempDirectory(TEMP_DIR);
		$config->addConfig(__DIR__ . '/files/nette-reset.neon');
		$config->addConfig(__DIR__ . '/files/default.neon');

		return $config->createContainer();
	}

}

(new RabbitMqExtensionTest())->run();
