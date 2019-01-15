<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\Command;

use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;



class PurgeConsumerCommand extends Command
{

	/**
	 * @inject
	 * @var Connection
	 */
	public $connection;



	protected function configure() : void
	{
		$this
			->setName('rabbitmq:purge')
			->setDescription('Purges all messages in queue associated with given consumer')
			->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
			->addOption('no-confirmation', NULL, InputOption::VALUE_NONE, 'Whether it must be confirmed before purging');
	}



	protected function execute(InputInterface $input, OutputInterface $output) : int
	{
		$noConfirmation = (bool) $input->getOption('no-confirmation');
		/** @var string $consumerName */
		$consumerName = $input->getArgument('name');

		if (!$noConfirmation && $input->isInteractive()) {
			/** @var string $consumerName */

			$confirmation = $this->getHelper('dialog')->askConfirmation(
				$output,
				sprintf('<question>Are you sure you wish to purge "%s" queue? (y/n)</question>', $consumerName),
				FALSE
			);
			if (!$confirmation) {
				$output->writeln('<error>Purging cancelled!</error>');

				return 1;
			}
		}

		/** @var Consumer $consumer */
		$consumer = $this->connection->getConsumer($consumerName);
		$consumer->purge();

		return 0;
	}

}
