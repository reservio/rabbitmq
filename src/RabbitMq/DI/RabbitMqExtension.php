<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\DI;

use Damejidlo\RabbitMq\Command\ConsumerCommand;
use Damejidlo\RabbitMq\Command\PurgeConsumerCommand;
use Damejidlo\RabbitMq\Command\SetupFabricCommand;
use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use Damejidlo\RabbitMq\IConsumerRunnerFactory;
use Damejidlo\RabbitMq\Producer;
use Kdyby\Console\DI\ConsoleExtension;
use Nette;
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
		'publisherConfirms' => NULL, // depends on debugMode parameter
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
		'heartbeat' => 60,
		'read_write_timeout' => 130.0,
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
		'publisherConfirms' => NULL, // inherits from `rabbitmq: publisherConfirms:`
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
		'prefetchCount' => 1,
	];

	/**
	 * @var mixed
	 */
	public $bindingDefaults = [
		'exchange' => NULL,
		'routingKey' => '',
	];



	public function __construct(bool $debugMode = FALSE)
	{
		$this->defaults['autoSetupFabric'] = $this->defaults['autoSetupFabric'] ?? $debugMode;
		$this->defaults['publisherConfirms'] = $this->defaults['publisherConfirms'] ?? ! $debugMode;
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

		$this->loadConnection($this->config['connection']);
		$this->loadProducers($this->config['producers']);
		$this->loadConsumers($this->config['consumers']);
		$this->loadConsole();
	}



	/**
	 * @param mixed[] $config
	 */
	private function loadConnection(array $config) : void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig($this->connectionDefaults, $config, "{$this->name}.connection");

		Nette\Utils\Validators::assertField($config, 'user', 'string:3..', "The config item '%' of connection {$this->name}");
		Nette\Utils\Validators::assertField($config, 'password', 'string:3..', "The config item '%' of connection {$this->name}");

		$builder->addDefinition($this->prefix('connection'))
			->setClass(Connection::class)
			->setArguments($config)
			->addSetup('injectServiceLocator');
	}



	/**
	 * @param mixed[] $producers
	 */
	private function loadProducers(array $producers) : void
	{
		$producersMap = [];

		$producerDefaults = $this->producerDefaults;
		$producerDefaults['autoSetupFabric'] = $producerDefaults['autoSetupFabric'] ?? $this->config['autoSetupFabric'];
		$producerDefaults['publisherConfirms'] = $producerDefaults['publisherConfirms'] ?? $this->config['publisherConfirms'];

		$builder = $this->getContainerBuilder();
		foreach ($producers as $name => $config) {
			$config = $this->validateConfig($producerDefaults, $config, "{$this->name}.producers.{$name}");

			if (!is_array($config['exchange'])) {
				$config['exchange'] = ['name' => $config['exchange']];
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
					'connection' => $this->prefix('@connection'),
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

			if ($config['publisherConfirms'] === TRUE) {
				$producer->addSetup('enablePublisherConfirms');
			}

			$producersMap[$name] = $serviceName;
		}

		$builder->getDefinition($this->prefix('connection'))
			->addSetup('injectProducersMap', [$producersMap]);

		$builder->addDefinition($this->prefix('consumerRunnerFactory'))
			->setImplement(IConsumerRunnerFactory::class);
	}



	/**
	 * @param mixed[] $consumers
	 */
	private function loadConsumers(array $consumers) : void
	{
		$consumersMap = [];

		$consumerDefaults = $this->consumerDefaults;
		$consumerDefaults['autoSetupFabric'] = $consumerDefaults['autoSetupFabric'] ?? $this->config['autoSetupFabric'];

		$builder = $this->getContainerBuilder();
		foreach ($consumers as $name => $config) {
			$config = $this->validateConfig($consumerDefaults, $config, "{$this->name}.consumers.{$name}");

			if (!is_array($config['queue'])) {
				$config['queue'] = ['name' => $config['queue']];
			}
			$config['queue'] = $this->validateConfig($this->queueDefaults, $config['queue'], "{$this->name}.consumers.{$name}.queue");

			Validators::assertField($config['queue'], 'name', 'string:3..', "The config item 'queue.%' of consumer {$this->name}.{$name}");
			$queueName = $config['queue']['name'];
			unset($config['queue']['name']);

			$config['qos'] = $this->validateConfig($this->qosDefaults, $config['qos'], "{$this->name}.consumers.{$name}");

			$serviceName = $this->prefix('consumer.' . $name);
			$consumer = $builder->addDefinition($serviceName)
				->setClass(Consumer::class)
				->setArguments([
					'connection' => $this->prefix('@connection'),
					'queueName' => $queueName,
					'callback' => $this->fixCallback($config['callback']),
					'queueOptions' => $config['queue'],
					'prefetchSize' => $config['qos']['prefetchSize'],
					'prefetchCount' => $config['qos']['prefetchCount'],
				])
				->addTag(self::TAG_CONSUMER)
				->setAutowired(FALSE);

			Validators::assert($config['binding'], 'array[]', "The config item 'binding.%' of consumer {$this->name}.{$name}");
			foreach ($config['binding'] as $i => $binding) {
				$binding = $this->validateConfig($this->bindingDefaults, $binding);
				Validators::assertField($binding, 'exchange', 'string:3..', "The config item 'binding[$i].%' of consumer {$this->name}.{$name}");
				$consumer->addSetup('addBinding', $binding);
			}

			if ($config['autoSetupFabric'] === FALSE) {
				$consumer->addSetup('disableAutoSetupFabric');
			}

			$consumersMap[$name] = $serviceName;
		}

		$builder->getDefinition($this->prefix('connection'))
			->addSetup('injectConsumersMap', [$consumersMap]);
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
