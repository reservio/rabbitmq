<?php

/**
 * Test: Damejidlo\RabbitMq\Extension.
 *
 * @testCase DamejidloTests\RabbitMq\ExtensionTest
 * @author Filip Procházka <filip@prochazka.su>
 * @package Damejidlo\RabbitMq
 */

namespace DamejidloTests\RabbitMq;

use Damejidlo;
use DamejidloTests;
use Nette;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Tester;
use Tester\Assert;

require_once __DIR__ . '/TestCase.php';



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class ExtensionTest extends TestCase
{

	/**
	 * @return \SystemContainer|\Nette\DI\Container
	 */
	protected function createContainer()
	{
		$config = new Nette\Configurator();
		$config->setTempDirectory(TEMP_DIR);
		Damejidlo\RabbitMq\DI\RabbitMqExtension::register($config);
		$config->addConfig(__DIR__ . '/files/nette-reset.neon');
		$config->addConfig(__DIR__ . '/files/default.neon');

		return $config->createContainer();
	}



	public function testFunctional()
	{
		$dic = $this->createContainer();

		// foo was defined first in config
		Assert::true($dic->getByType('Damejidlo\RabbitMq\Connection') instanceof AMQPStreamConnection);
		Assert::same($dic->getByType('Damejidlo\RabbitMq\Connection'), $dic->getService('rabbitmq.foo_connection.connection'));

		// only the first defined connection is autowired
		Assert::true($dic->getService('rabbitmq.default.connection') instanceof AMQPStreamConnection);
		Assert::notSame($dic->getByType('Damejidlo\RabbitMq\Connection'), $dic->getService('rabbitmq.default.connection'));

		Assert::true($dic->getService('rabbitmq.producer.foo_producer') instanceof Damejidlo\RabbitMq\Producer);
		Assert::true($dic->getService('rabbitmq.producer.default_producer') instanceof Damejidlo\RabbitMq\Producer);

		Assert::true($dic->getService('rabbitmq.consumer.foo_consumer') instanceof Damejidlo\RabbitMq\Consumer);
		Assert::true($dic->getService('rabbitmq.consumer.default_consumer') instanceof Damejidlo\RabbitMq\Consumer);
		Assert::true($dic->getService('rabbitmq.consumer.qos_test_consumer') instanceof Damejidlo\RabbitMq\Consumer);
		Assert::true($dic->getService('rabbitmq.consumer.multi_test_consumer') instanceof Damejidlo\RabbitMq\MultipleConsumer);
		Assert::true($dic->getService('rabbitmq.consumer.foo_anon_consumer') instanceof Damejidlo\RabbitMq\AnonymousConsumer);
		Assert::true($dic->getService('rabbitmq.consumer.default_anon_consumer') instanceof Damejidlo\RabbitMq\AnonymousConsumer);

		Assert::true($dic->getService('rabbitmq.rpcClient.foo_client') instanceof Damejidlo\RabbitMq\RpcClient);
		Assert::true($dic->getService('rabbitmq.rpcClient.default_client') instanceof Damejidlo\RabbitMq\RpcClient);

		Assert::true($dic->getService('rabbitmq.rpcServer.foo_server') instanceof Damejidlo\RabbitMq\RpcServer);
		Assert::true($dic->getService('rabbitmq.rpcServer.default_server') instanceof Damejidlo\RabbitMq\RpcServer);
	}

}

\run(new ExtensionTest());
