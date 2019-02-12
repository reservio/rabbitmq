<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\DI;

use Damejidlo\RabbitMq\Command\ConsumerCommand;
use Damejidlo\RabbitMq\Command\PurgeConsumerCommand;
use Damejidlo\RabbitMq\Command\SetupFabricCommand;
use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use Damejidlo\RabbitMq\Producer;
use Kdyby\Console\DI\ConsoleExtension;
use Nette;
use Nette\Utils\AssertionException;
use Nette\Utils\Validators;
use PhpAmqpLib\Message\AMQPMessage;



class RabbitMqExtension extends Nette\DI\CompilerExtension
{

	public const TAG_PRODUCER = 'damejidlo.rabbitmq.producer';
	public const TAG_CONSUMER = 'damejidlo.rabbitmq.consumer';

	/**
	 * @var mixed[]
	 */
	public $defaults = [
		'connection' => [],
		'producers' => [],
		'consumers' => [],
		'autoSetupFabric' => NULL, // depends on debugMode parameter
	];

	/**
	 * @var mixed[]
	 */
	public $connectionDefaults = [
		'host' => '127.0.0.1',
		'port' => 5672,
		'user' => NULL,
		'password' => NULL,
		'vhost' => '/',
	];

	/**
	 * @var mixed[]
	 */
	public $producerDefaults = [
		'connection' => 'default',
		'class' => Producer::class,
		'exchange' => [],
		'contentType' => 'text/plain',
		'deliveryMode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
		'routingKey' => '',
		'autoSetupFabric' => NULL, // inherits from `rabbitmq: autoSetupFabric:`
	];

	/**
	 * @var mixed[]
	 */
	public $consumerDefaults = [
		'connection' => 'default',
		'queue' => [],
		'callback' => NULL,
		'binding' => [],
		'qos' => [],
		'idleTimeout' => NULL,
		'autoSetupFabric' => NULL, // inherits from `rabbitmq: autoSetupFabric:`
	];

	/**
	 * @var mixed[]
	 */
	public $exchangeDefaults = [
		'name' => NULL,
		'type' => 'direct',
		'passive' => FALSE,
		'durable' => TRUE,
		'autoDelete' => FALSE,
		'internal' => FALSE,
		'nowait' => FALSE,
		'arguments' => NULL,
		'ticket' => NULL,
	];

	/**
	 * @var mixed[]
	 */
	public $queueDefaults = [
		'name' => NULL,
		'passive' => FALSE,
		'durable' => TRUE,
		'noLocal' => FALSE,
		'noAck' => FALSE,
		'exclusive' => FALSE,
		'autoDelete' => FALSE,
		'nowait' => FALSE,
		'arguments' => NULL,
		'ticket' => NULL,
	];

	/**
	 * @var mixed[]
	 */
	public $qosDefaults = [
		'prefetchSize' => 0,
		'prefetchCount' => 0,
	];

	/**
	 * @var mixed
	 */
	public $bindingDefaults = [
		'exchange' => NULL,
		'routingKey' => '',
	];

	/**
	 * @var mixed[]
	 */
	private $connectionsMeta = [];



	public function __construct(bool $debugMode = FALSE)
	{
		$this->defaults['autoSetupFabric'] = $this->defaults['autoSetupFabric'] ?? $debugMode;
	}



	public function loadConfiguration() : void
	{
		$this->validateConfig($this->defaults);

		foreach ($this->compiler->getExtensions() as $extension) {
			if ($extension instanceof IProducersProvider) {
				$producers = $extension->getRabbitProducers();
				Validators::assert($producers, 'array:1..');
				$this->config['producers'] = array_merge($this->config['producers'], $producers);
			}
			if ($extension instanceof IConsumersProvider) {
				$consumers = $extension->getRabbitConsumers();
				Validators::assert($consumers, 'array:1..');
				$this->config['consumers'] = array_merge($this->config['consumers'], $consumers);
			}
		}

		$this->loadConnections($this->config['connection']);
		$this->loadProducers($this->config['producers']);
		$this->loadConsumers($this->config['consumers']);

		$builder = $this->getContainerBuilder();
		foreach ($this->connectionsMeta as $name => $meta) {
			$connection = $builder->getDefinition($meta['serviceId']);
			$connection->addSetup('injectServiceLocator');
			$connection->addSetup('injectServiceMap', [$meta['producers'], $meta['consumers']]);
		}

		$this->loadConsole();
	}



	/**
	 * @param mixed[] $connections
	 */
	private function loadConnections(array $connections) : void
	{
		$this->connectionsMeta = []; // reset

		if (isset($connections['user'])) {
			$connections = ['default' => $connections];
		}

		$builder = $this->getContainerBuilder();
		foreach ($connections as $name => $config) {
			$config = $this->validateConfig($this->connectionDefaults, $config, "{$this->name}.connection.{$name}");

			Nette\Utils\Validators::assertField($config, 'user', 'string:3..', "The config item '%' of connection {$this->name}.{$name}");
			Nette\Utils\Validators::assertField($config, 'password', 'string:3..', "The config item '%' of connection {$this->name}.{$name}");

			$serviceName = $this->prefix($name . '.connection');
			$connection = $builder->addDefinition($serviceName)
				->setClass(Connection::class)
				->setArguments([
					$config['host'],
					$config['port'],
					$config['user'],
					$config['password'],
					$config['vhost'],
				]);

			$this->connectionsMeta[$name] = [
				'serviceId' => $serviceName,
				'producers' => [],
				'consumers' => [],
			];

			// only the first connection is autowired
			if (count($this->connectionsMeta) > 1) {
				$connection->setAutowired(FALSE);
			}
		}
	}



	/**
	 * @param mixed[] $producers
	 */
	private function loadProducers(array $producers) : void
	{
		$producerDefaults = $this->producerDefaults;
		$producerDefaults['autoSetupFabric'] = $producerDefaults['autoSetupFabric'] ?? $this->config['autoSetupFabric'];

		$builder = $this->getContainerBuilder();
		foreach ($producers as $name => $config) {
			$config = $this->validateConfig($producerDefaults, $config, "{$this->name}.producers.{$name}");

			if (!isset($this->connectionsMeta[$config['connection']])) {
				throw new AssertionException("Connection {$config['connection']} required in producer {$this->name}.{$name} was not defined.");
			}

			$config['exchange'] = $this->validateConfig($this->exchangeDefaults, $config['exchange'], "{$this->name}.producers.{$name}.exchange");

			Validators::assertField($config['exchange'], 'name', 'string:3..', "The config item 'exchange.%' of producer {$this->name}.{$name}");
			$exchangeName = $config['exchange']['name'];
			unset($config['exchange']['name']);

			Validators::assertField($config['exchange'], 'type', 'string:3..', "The config item 'exchange.%' of producer {$this->name}.{$name}");
			$exchangeType = $config['exchange']['type'];
			unset($config['exchange']['type']);


			$serviceName = $this->prefix('producer.' . $name);
			$producer = $builder->addDefinition($serviceName)
				->setClass(Producer::class)
				->setFactory($config['class'])
				->setArguments([
					'connection' => '@' . $this->connectionsMeta[$config['connection']]['serviceId'],
					'exchangeName' => $exchangeName,
					'exchangeType' => $exchangeType,
					'routingKey' => $config['routingKey'],
					'exchangeOptions' => $config['exchange'],
				])
				->addSetup('setContentType', [$config['contentType']])
				->addSetup('setDeliveryMode', [$config['deliveryMode']])
				->addTag(self::TAG_PRODUCER);

			if ($config['autoSetupFabric'] === FALSE) {
				$producer->addSetup('disableAutoSetupFabric');
			}

			$this->connectionsMeta[$config['connection']]['producers'][$name] = $serviceName;
		}
	}



	/**
	 * @param mixed[] $consumers
	 */
	private function loadConsumers(array $consumers) : void
	{
		$consumerDefaults = $this->consumerDefaults;
		$consumerDefaults['autoSetupFabric'] = $consumerDefaults['autoSetupFabric'] ?? $this->config['autoSetupFabric'];

		$builder = $this->getContainerBuilder();
		foreach ($consumers as $name => $config) {
			$config = $this->validateConfig($consumerDefaults, $config, "{$this->name}.consumers.{$name}");

			if (!isset($this->connectionsMeta[$config['connection']])) {
				throw new AssertionException("Connection {$config['connection']} required in consumer {$this->name}.{$name} was not defined.");
			}

			$serviceName = $this->prefix('consumer.' . $name);
			$consumer = $builder->addDefinition($serviceName)
				->addTag(self::TAG_CONSUMER)
				->setAutowired(FALSE);

			$config['queue'] = $this->validateConfig($this->queueDefaults, $config['queue'], "{$this->name}.consumers.{$name}.queue");
			$consumer
				->setClass(Consumer::class)
				->addSetup('setQueueOptions', [$config['queue']])
				->addSetup('setCallback', [$this->fixCallback($config['callback'])]);

			Validators::assert($config['binding'], 'array[]', "The config item 'binding.%' of consumer {$this->name}.{$name}");
			foreach ($config['binding'] as $i => $binding) {
				$binding = $this->validateConfig($this->bindingDefaults, $binding);
				Validators::assertField($binding, 'exchange', 'string:3..', "The config item 'binding[$i].%' of consumer {$this->name}.{$name}");
				$consumer->addSetup('addBinding', $binding);
			}

			$consumer->setArguments(['@' . $this->connectionsMeta[$config['connection']]['serviceId']]);

			$config['qos'] = $this->validateConfig($this->qosDefaults, $config['qos'], "{$this->name}.consumers.{$name}");
			$consumer->addSetup('setQosOptions', [
				$config['qos']['prefetchSize'],
				$config['qos']['prefetchCount'],
			]);

			if ($config['idleTimeout']) {
				$consumer->addSetup('setIdleTimeout', [$config['idleTimeout']]);
			}

			if ($config['autoSetupFabric'] === FALSE) {
				$consumer->addSetup('disableAutoSetupFabric');
			}

			$this->connectionsMeta[$config['connection']]['consumers'][$name] = $serviceName;
		}
	}



	private function loadConsole() : void
	{
		if (!class_exists('Kdyby\Console\DI\ConsoleExtension') || PHP_SAPI !== 'cli') {
			return;
		}

		$commands = [
			ConsumerCommand::class,
			PurgeConsumerCommand::class,
			SetupFabricCommand::class,
		];

		$builder = $this->getContainerBuilder();
		foreach ($commands as $i => $class) {
			$builder->addDefinition($this->prefix('console.' . $i))
				->setClass($class)
				->addTag(ConsoleExtension::COMMAND_TAG);
		}
	}



	/**
	 * @param mixed $callback
	 * @return mixed
	 */
	private function fixCallback($callback)
	{
		if (is_string($callback) && substr_count($callback, '::')) {
			$callback = explode('::', $callback, 2);
		}

		return $callback;
	}

}
