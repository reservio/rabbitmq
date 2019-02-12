<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\DI;

use Damejidlo\RabbitMq\Command\ConsumerCommand;
use Damejidlo\RabbitMq\Command\PurgeConsumerCommand;
use Damejidlo\RabbitMq\Command\SetupFabricCommand;
use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use Damejidlo\RabbitMq\IProducer;
use Damejidlo\RabbitMq\Producer;
use Kdyby\Console\DI\ConsoleExtension;
use Nette;
use Nette\DI\Config\Helpers;
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
		'exchange' => [],
		'queue' => [],
		'callback' => NULL,
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
		'name' => '',
		'passive' => FALSE,
		'durable' => TRUE,
		'noLocal' => FALSE,
		'noAck' => FALSE,
		'exclusive' => FALSE,
		'autoDelete' => FALSE,
		'nowait' => FALSE,
		'arguments' => NULL,
		'ticket' => NULL,
		'routing_keys' => [],
	];

	/**
	 * @var mixed[]
	 */
	public $qosDefaults = [
		'prefetchSize' => 0,
		'prefetchCount' => 0,
	];

	/**
	 * @var mixed[]
	 */
	private $connectionsMeta = [];

	/**
	 * @var mixed[]
	 */
	private $producersConfig = [];



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

			$serviceName = $this->prefix('producer.' . $name);
			$producer = $builder->addDefinition($serviceName)
				->setFactory($config['class'], ['@' . $this->connectionsMeta[$config['connection']]['serviceId']])
				->setClass(IProducer::class)
				->addSetup('setContentType', [$config['contentType']])
				->addSetup('setDeliveryMode', [$config['deliveryMode']])
				->addSetup('setRoutingKey', [$config['routingKey']])
				->addTag(self::TAG_PRODUCER);

			$config['exchange'] = $this->validateConfig($this->exchangeDefaults, $config['exchange'], "{$this->name}.producers.{$name}.exchange");
			Validators::assertField($config['exchange'], 'name', 'string:3..', "The config item 'exchange.%' of producer {$this->name}.{$name}");
			Validators::assertField($config['exchange'], 'type', 'string:3..', "The config item 'exchange.%' of producer {$this->name}.{$name}");
			$producer->addSetup('setExchangeOptions', [$config['exchange']]);

			if ($config['autoSetupFabric'] === FALSE) {
				$producer->addSetup('disableAutoSetupFabric');
			}

			$this->connectionsMeta[$config['connection']]['producers'][$name] = $serviceName;
			$this->producersConfig[$name] = $config;
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
			$config = $this->extendConsumerFromProducer($name, $config);

			if (!isset($this->connectionsMeta[$config['connection']])) {
				throw new AssertionException("Connection {$config['connection']} required in consumer {$this->name}.{$name} was not defined.");
			}

			$serviceName = $this->prefix('consumer.' . $name);
			$consumer = $builder->addDefinition($serviceName)
				->addTag(self::TAG_CONSUMER)
				->setAutowired(FALSE);

			if (!empty($config['exchange'])) {
				$config['exchange'] = $this->validateConfig($this->exchangeDefaults, $config['exchange'], "{$this->name}.consumers.{$name}.exchange");
				Validators::assertField($config['exchange'], 'name', 'string:3..', "The config item 'exchange.%' of consumer {$this->name}.{$name}");
				Validators::assertField($config['exchange'], 'type', 'string:3..', "The config item 'exchange.%' of consumer {$this->name}.{$name}");
				$consumer->addSetup('setExchangeOptions', [$config['exchange']]);
			}

			if (!empty($config['queue'])) {
				$config['queue'] = $this->validateConfig($this->queueDefaults, $config['queue'], "{$this->name}.consumers.{$name}.queue");
				$consumer
					->setClass(Consumer::class)
					->addSetup('setQueueOptions', [$config['queue']])
					->addSetup('setCallback', [$this->fixCallback($config['callback'])]);

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



	/**
	 * @param string $consumerName
	 * @param mixed[] $config
	 * @return mixed[]
	 */
	private function extendConsumerFromProducer(string $consumerName, array $config) : array
	{
		if (!isset($config[Helpers::EXTENDS_KEY])) {
			return $config;
		}
		$producerName = $config[Helpers::EXTENDS_KEY];

		if (!isset($this->producersConfig[$producerName])) {
			throw new AssertionException("Consumer {$this->name}.{$consumerName} cannot extend unknown producer {$this->name}.{$producerName}.");
		}
		$producerConfig = $this->producersConfig[$producerName];

		if (!empty($producerConfig['exchange'])) {
			$config['exchange'] = Helpers::merge($config['exchange'], $producerConfig['exchange']);
		}

		if (!empty($producerConfig['queue'])) {
			$config['queue'] = Helpers::merge($config['queue'], $producerConfig['queue']);
		}

		return $config;
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
