<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\Command;

use Damejidlo\RabbitMq\BaseConsumer;
use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use Nette\Utils\Validators;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;



class ConsumerCommand extends Command
{

	/**
	 * @inject
	 * @var Connection
	 */
	public $connection;

	/**
	 * @var BaseConsumer
	 */
	protected $consumer;

	/**
	 * @var int
	 */
	protected $amount;



	protected function configure() : void
	{
		$this
			->setName('rabbitmq:consumer')
			->setDescription('Starts a configured consumer')
			->addArgument('name', InputArgument::REQUIRED, 'Consumer Name')
			->addOption('messages', 'm', InputOption::VALUE_OPTIONAL, 'Messages to consume', 0)
			->addOption('route', 'r', InputOption::VALUE_OPTIONAL, 'Routing Key', '')
			->addOption('memory-limit', 'l', InputOption::VALUE_OPTIONAL, 'Allowed memory for this process', NULL)
			->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable Debugging')
			->addOption('without-signals', 'w', InputOption::VALUE_NONE, 'Disable catching of system signals');
	}



	protected function initialize(InputInterface $input, OutputInterface $output) : void
	{
		parent::initialize($input, $output);

		if (defined('AMQP_WITHOUT_SIGNALS') === FALSE) {
			define('AMQP_WITHOUT_SIGNALS', $input->getOption('without-signals'));
		}

		if (!AMQP_WITHOUT_SIGNALS && extension_loaded('pcntl')) {
			if (!function_exists('pcntl_signal')) {
				throw new \BadFunctionCallException("Function 'pcntl_signal' is referenced in the php.ini 'disable_functions' and can't be called.");
			}

			pcntl_signal(SIGTERM, [$this, 'signalTerm']);
			pcntl_signal(SIGINT, [$this, 'signalInt']);
			pcntl_signal(SIGHUP, [$this, 'signalHup']);
		}

		if (defined('AMQP_DEBUG') === FALSE) {
			define('AMQP_DEBUG', (bool) $input->getOption('debug'));
		}

		/** @var string|int $amount */
		$amount = $input->getOption('messages');
		Validators::assert($amount, 'numericint|null', 'messages option');
		$amount = (int) $amount;
		if ($amount < 0) {
			throw new \InvalidArgumentException("The -m option should be null or greater than 0");
		}
		$this->amount = $amount;

		/** @var string $consumerName */
		$consumerName = $input->getArgument('name');
		$this->consumer = $this->connection->getConsumer($consumerName);

		/** @var string|int|NULL $memoryLimit */
		$memoryLimit = $input->getOption('memory-limit');
		if ($memoryLimit !== NULL) {
			Validators::assert($memoryLimit, 'numericint', 'memory limit');
			$memoryLimit = (int) $memoryLimit;
			if ($memoryLimit < 0) {
				throw new \InvalidArgumentException("The -l option should be null or greater than 0");
			}
			if (!$this->consumer instanceof Consumer) {
				throw new \UnexpectedValueException('Cannot set memory limit on consumer.');
			}
			$this->consumer->setMemoryLimit($memoryLimit);
		}

		/** @var string $routingKey */
		$routingKey = $input->getOption('route');
		if ($routingKey !== '') {
			$this->consumer->setRoutingKey($routingKey);
		}
	}



	protected function execute(InputInterface $input, OutputInterface $output) : int
	{
		$this->consumer->consume($this->amount);
		return 0;
	}



	/**
	 * @internal for pcntl only
	 */
	public function signalTerm() : void
	{
		if ($this->consumer) {
			pcntl_signal(SIGTERM, SIG_DFL);
			$this->consumer->forceStopConsumer();
		}
	}



	/**
	 * @internal for pcntl only
	 */
	public function signalInt() : void
	{
		if ($this->consumer) {
			pcntl_signal(SIGINT, SIG_DFL);
			$this->consumer->forceStopConsumer();
		}
	}



	/**
	 * @internal for pcntl only
	 */
	public function signalHup() : void
	{
		if ($this->consumer) {
			pcntl_signal(SIGHUP, SIG_DFL);
			$this->consumer->forceStopConsumer();
		}
	}

}
