<?php

namespace Damejidlo\RabbitMq\DI;

use Damejidlo;
use Nette;
use Nette\DI\Compiler;
use Nette\PhpGenerator as Code;
use Nette\DI\Config;
use Nette\Utils\Validators;



/**
 * @author Alvaro Videla <videlalvaro@gmail.com>
 * @author Filip Procházka <filip@prochazka.su>
 */
class RabbitMqExtension extends Nette\DI\CompilerExtension
{

	const TAG_PRODUCER = 'kdyby.rabbitmq.producer';
	const TAG_CONSUMER = 'kdyby.rabbitmq.consumer';

	/**
	 * @var array
	 */
	public $defaults = [
		'connection' => [],
		'producers' => [],
		'consumers' => [],
		'debugger' => '%debugMode%',
		'autoSetupFabric' => '%debugMode%',
	];

	/**
	 * @var array
	 */
	public $connectionDefaults = [
		'host' => '127.0.0.1',
		'port' => 5672,
		'user' => NULL,
		'password' => NULL,
		'vhost' => '/',
	];

	/**
	 * @var array
	 */
	public $producersDefaults = [
		'connection' => 'default',
		'class' => 'Damejidlo\RabbitMq\Producer',
		'exchange' => [],
		'queue' => [],
		'contentType' => 'text/plain',
		'deliveryMode' => 2,
		'routingKey' => '',
		'autoSetupFabric' => NULL, // inherits from `rabbitmq: autoSetupFabric:`
	];

	/**
	 * @var array
	 */
	public $consumersDefaults = [
		'connection' => 'default',
		'exchange' => [],
		'queues' => [], // for multiple consumers
		'queue' => [], // for single consumer
		'callback' => NULL,
		'qos' => [],
		'idleTimeout' => NULL,
		'autoSetupFabric' => NULL, // inherits from `rabbitmq: autoSetupFabric:`
	];

	/**
	 * @var array
	 */
	public $exchangeDefaults = [
		'passive' => FALSE,
		'durable' => TRUE,
		'autoDelete' => FALSE,
		'internal' => FALSE,
		'nowait' => FALSE,
		'arguments' => NULL,
		'ticket' => NULL,
		'declare' => TRUE,
	];

	/**
	 * @var array
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
	 * @var array
	 */
	public $qosDefaults = [
		'prefetchSize' => 0,
		'prefetchCount' => 0,
		'global' => FALSE,
	];

	/**
	 * @var array
	 */
	protected $connectionsMeta = [];

	/**
	 * @var array
	 */
	private $producersConfig = [];



	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		foreach ($this->compiler->getExtensions() as $extension) {
			if ($extension instanceof IProducersProvider) {
				$producers = $extension->getRabbitProducers();
				Validators::assert($producers, 'array:1..');
				$config['producers'] = array_merge($config['producers'], $producers);
			}
			if ($extension instanceof IConsumersProvider) {
				$consumers = $extension->getRabbitConsumers();
				Validators::assert($consumers, 'array:1..');
				$config['consumers'] = array_merge($config['consumers'], $consumers);
			}
		}

		if ($unexpected = array_diff(array_keys($config), array_keys($this->defaults))) {
			throw new Nette\Utils\AssertionException("Unexpected key '" . implode("', '", $unexpected) . "' in configuration of {$this->name}.");
		}

		$builder->parameters[$this->name] = $config;

		$this->loadConnections($config['connection']);
		$this->loadProducers($config['producers']);
		$this->loadConsumers($config['consumers']);

		foreach ($this->connectionsMeta as $name => $meta) {
			$connection = $builder->getDefinition($meta['serviceId']);

			if ($config['debugger']) {
				$builder->addDefinition($panelService = $meta['serviceId'] . '.panel')
					->setClass('Damejidlo\RabbitMq\Diagnostics\Panel')
					->addSetup('injectServiceMap', [
						$meta['consumers'],
					])
					->setInject(FALSE)
					->setAutowired(FALSE);

				$connection->addSetup('injectPanel', ['@' . $panelService]);
			}

			$connection->addSetup('injectServiceLocator');
			$connection->addSetup('injectServiceMap', [
				$meta['producers'],
				$meta['consumers'],
			]);
		}

		$this->loadConsole();
	}



	public function beforeCompile()
	{
		unset($this->getContainerBuilder()->parameters[$this->name]);
	}



	protected function loadConnections($connections)
	{
		$this->connectionsMeta = []; // reset

		if (isset($connections['user'])) {
			$connections = ['default' => $connections];
		}

		$builder = $this->getContainerBuilder();
		foreach ($connections as $name => $config) {
			$config = $this->mergeConfig($config, $this->connectionDefaults);

			Nette\Utils\Validators::assertField($config, 'user', 'string:3..', "The config item '%' of connection {$this->name}.{$name}");
			Nette\Utils\Validators::assertField($config, 'password', 'string:3..', "The config item '%' of connection {$this->name}.{$name}");

			$connection = $builder->addDefinition($serviceName = $this->prefix($name . '.connection'))
				->setClass('Damejidlo\RabbitMq\Connection')
				->setArguments([
					$config['host'],
					$config['port'],
					$config['user'],
					$config['password'],
					$config['vhost']
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



	protected function loadProducers($producers)
	{
		$builder = $this->getContainerBuilder();

		foreach ($producers as $name => $config) {
			$config = $this->mergeConfig($config, ['autoSetupFabric' => $builder->parameters[$this->name]['autoSetupFabric']] + $this->producersDefaults);

			if (!isset($this->connectionsMeta[$config['connection']])) {
				throw new Nette\Utils\AssertionException("Connection {$config['connection']} required in producer {$this->name}.{$name} was not defined.");
			}

			$producer = $builder->addDefinition($serviceName = $this->prefix('producer.' . $name))
				->setFactory($config['class'], ['@' . $this->connectionsMeta[$config['connection']]['serviceId']])
				->setClass('Damejidlo\RabbitMq\IProducer')
				->addSetup('setContentType', [$config['contentType']])
				->addSetup('setDeliveryMode', [$config['deliveryMode']])
				->addSetup('setRoutingKey', [$config['routingKey']])
				->addTag(self::TAG_PRODUCER);

			if (!empty($config['exchange'])) {
				$config['exchange'] = $this->mergeConfig($config['exchange'], $this->exchangeDefaults);
				Nette\Utils\Validators::assertField($config['exchange'], 'name', 'string:3..', "The config item 'exchange.%' of producer {$this->name}.{$name}");
				Nette\Utils\Validators::assertField($config['exchange'], 'type', 'string:3..', "The config item 'exchange.%' of producer {$this->name}.{$name}");
				$producer->addSetup('setExchangeOptions', [$config['exchange']]);
			}

			$config['queue'] = $this->mergeConfig($config['queue'], $this->queueDefaults);
			$producer->addSetup('setQueueOptions', [$config['queue']]);

			if ($config['autoSetupFabric'] === FALSE) {
				$producer->addSetup('disableAutoSetupFabric');
			}

			$this->connectionsMeta[$config['connection']]['producers'][$name] = $serviceName;
			$this->producersConfig[$name] = $config;
		}
	}



	protected function loadConsumers($consumers)
	{
		$builder = $this->getContainerBuilder();

		foreach ($consumers as $name => $config) {
			$config = $this->mergeConfig($config, ['autoSetupFabric' => $builder->parameters[$this->name]['autoSetupFabric']] + $this->consumersDefaults);
			$config = $this->extendConsumerFromProducer($name, $config);

			if (!isset($this->connectionsMeta[$config['connection']])) {
				throw new Nette\Utils\AssertionException("Connection {$config['connection']} required in consumer {$this->name}.{$name} was not defined.");
			}

			$consumer = $builder->addDefinition($serviceName = $this->prefix('consumer.' . $name))
				->addTag(self::TAG_CONSUMER)
				->setAutowired(FALSE);

			if (!empty($config['exchange'])) {
				Nette\Utils\Validators::assertField($config['exchange'], 'name', 'string:3..', "The config item 'exchange.%' of consumer {$this->name}.{$name}");
				Nette\Utils\Validators::assertField($config['exchange'], 'type', 'string:3..', "The config item 'exchange.%' of consumer {$this->name}.{$name}");
				$consumer->addSetup('setExchangeOptions', [$this->mergeConfig($config['exchange'], $this->exchangeDefaults)]);
			}

			if (!empty($config['queues']) && empty($config['queue'])) {
				foreach ($config['queues'] as $queueName => $queueConfig) {
					$queueConfig['name'] = $queueName;
					$config['queues'][$queueName] = $this->mergeConfig($queueConfig, $this->queueDefaults);

					if (isset($queueConfig['callback'])) {
						$config['queues'][$queueName]['callback'] = self::fixCallback($queueConfig['callback']);
					}
				}

				$consumer
					->setClass('Damejidlo\RabbitMq\MultipleConsumer')
					->addSetup('setQueues', [$config['queues']]);

			} elseif (empty($config['queues']) && !empty($config['queue'])) {
				$consumer
					->setClass('Damejidlo\RabbitMq\Consumer')
					->addSetup('setQueueOptions', [$this->mergeConfig($config['queue'], $this->queueDefaults)])
					->addSetup('setCallback', [self::fixCallback($config['callback'])]);

			}

			$consumer->setArguments(['@' . $this->connectionsMeta[$config['connection']]['serviceId']]);

			if (array_filter($config['qos'])) { // has values
				$config['qos'] = $this->mergeConfig($config['qos'], $this->qosDefaults);
				$consumer->addSetup('setQosOptions', [
					$config['qos']['prefetchSize'],
					$config['qos']['prefetchCount'],
					$config['qos']['global'],
				]);
			}

			if ($config['idleTimeout']) {
				$consumer->addSetup('setIdleTimeout', [$config['idleTimeout']]);
			}

			if ($config['autoSetupFabric'] === FALSE) {
				$consumer->addSetup('disableAutoSetupFabric');
			}

			$this->connectionsMeta[$config['connection']]['consumers'][$name] = $serviceName;
		}
	}



	private function extendConsumerFromProducer(&$consumerName, $config)
	{
		if (isset($config[Config\Helpers::EXTENDS_KEY])) {
			$producerName = $config[Config\Helpers::EXTENDS_KEY];

		} elseif ($m = Nette\Utils\Strings::match($consumerName, '~^(?P<consumerName>[^>\s]+)\s*\<\s*(?P<producerName>[^>\s]+)\z~')) {
			$consumerName = $m['consumerName'];
			$producerName = $m['producerName'];

		} else {
			return $config;
		}

		if ( ! isset($this->producersConfig[$producerName])) {
			throw new Nette\Utils\AssertionException("Consumer {$this->name}.{$consumerName} cannot extend unknown producer {$this->name}.{$producerName}.");
		}
		$producerConfig = $this->producersConfig[$producerName];

		if (!empty($producerConfig['exchange'])) {
			$config['exchange'] = $this->mergeConfig($config['exchange'], $producerConfig['exchange']);
		}

		if (empty($config['queues']) && !empty($producerConfig['queue'])) {
			$config['queue'] = $this->mergeConfig($config['queue'], $producerConfig['queue']);
		}

		return $config;
	}



	private function loadConsole()
	{
		if (!class_exists('Kdyby\Console\DI\ConsoleExtension') || PHP_SAPI !== 'cli') {
			return;
		}

		$builder = $this->getContainerBuilder();

		foreach ([
			'Damejidlo\RabbitMq\Command\ConsumerCommand',
			'Damejidlo\RabbitMq\Command\PurgeConsumerCommand',
			'Damejidlo\RabbitMq\Command\SetupFabricCommand',
		] as $i => $class) {
			$builder->addDefinition($this->prefix('console.' . $i))
				->setClass($class)
				->addTag(\Kdyby\Console\DI\ConsoleExtension::COMMAND_TAG);
		}
	}



	protected function mergeConfig($config, $defaults)
	{
		return Config\Helpers::merge($config, $this->compiler->getContainerBuilder()->expand($defaults));
	}



	protected static function fixCallback($callback)
	{
		list($callback) = self::filterArgs($callback);
		if ($callback instanceof Nette\DI\Statement && substr_count($callback->entity, '::') && empty($callback->arguments)) {
			$callback = explode('::', $callback->entity, 2);
		}

		return $callback;
	}



	/**
	 * @param string|\stdClass $statement
	 * @return Nette\DI\Statement[]
	 */
	protected static function filterArgs($statement)
	{
		return Nette\DI\Compiler::filterArguments([is_string($statement) ? new Nette\DI\Statement($statement) : $statement]);
	}



	public static function register(Nette\Configurator $configurator)
	{
		$configurator->onCompile[] = function ($config, Compiler $compiler) {
			$compiler->addExtension('rabbitmq', new RabbitMqExtension());
		};
	}

}
