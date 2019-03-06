<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\Command;

use Damejidlo\RabbitMq\Consumer;
use Damejidlo\RabbitMq\DI\RabbitMqExtension;
use Damejidlo\RabbitMq\Producer;
use Nette\DI\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;



class SetupFabricCommand extends Command
{

	private const SERVICE_TAGS = [
		RabbitMqExtension::TAG_PRODUCER,
		RabbitMqExtension::TAG_CONSUMER,
	];

	/**
	 * @inject
	 * @var Container
	 */
	public $container;



	protected function configure() : void
	{
		$this
			->setName('rabbitmq:setup-fabric')
			->setDescription('Sets up the Rabbit MQ fabric')
			->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging');
	}



	protected function execute(InputInterface $input, OutputInterface $output) : int
	{
		if (defined('AMQP_DEBUG') === FALSE) {
			define('AMQP_DEBUG', (bool) $input->getOption('debug'));
		}

		$output->writeln('Setting up the Rabbit MQ fabric');

		foreach (self::SERVICE_TAGS as $tag) {
			foreach ($this->container->findByTag($tag) as $serviceId => $meta) {
				/** @var Consumer|Producer $service */
				$service = $this->container->getService($serviceId);
				$service->setupFabric();
			}
		}

		return 0;
	}

}
