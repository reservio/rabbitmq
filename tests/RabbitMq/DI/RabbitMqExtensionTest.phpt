<?php
declare(strict_types = 1);

namespace DamejidloTests\RabbitMq\DI;

use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use Damejidlo\RabbitMq\IConsumerRunnerFactory;
use Damejidlo\RabbitMq\Producer;
use DamejidloTests\DjTestCase;
use Nette\Configurator;
use Nette\DI\Container;
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

		Assert::same($container->getByType(Connection::class), $container->getService('rabbitmq.connection'));

		Assert::type(IConsumerRunnerFactory::class, $container->getService('rabbitmq.consumerRunnerFactory'));

		Assert::type(Producer::class, $container->getService('rabbitmq.producer.foo_producer'));
		Assert::type(Producer::class, $container->getService('rabbitmq.producer.default_producer'));

		Assert::type(Consumer::class, $container->getService('rabbitmq.consumer.foo_consumer'));
		Assert::type(Consumer::class, $container->getService('rabbitmq.consumer.default_consumer'));
		Assert::type(Consumer::class, $container->getService('rabbitmq.consumer.qos_test_consumer'));
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
