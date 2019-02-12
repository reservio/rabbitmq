<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\Command;

use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\ConsumerRunner;
use Damejidlo\RabbitMq\IConsumerRunnerFactory;
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
	 * @inject
	 * @var IConsumerRunnerFactory
	 */
	public $consumerRunnerFactory;

	/**
	 * @var ConsumerRunner
	 */
	protected $consumerRunner;

	/**
	 * @var int
	 */
	protected $amount;



	protected function configure() : void
	{
		$this
			->setName('rabbitmq:consumer')
			->setDescription('Starts configured consumers')
			->addArgument('consumers', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Consumer names')
			->addOption('messages', 'm', InputOption::VALUE_OPTIONAL, 'Messages to consume', 0)
			->addOption('idle-timeout', 't', InputOption::VALUE_OPTIONAL, 'Idle timeout in seconds', 10)
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

		/** @var string[] $consumerNames */
		$consumerNames = $input->getArgument('consumers');
		if ($consumerNames === []) {
			throw new \InvalidArgumentException('At least one consumer name must be specified');
		}

		$this->consumerRunner = $this->consumerRunnerFactory->create();
		foreach ($consumerNames as $consumerName) {
			$this->consumerRunner->addConsumer($this->connection->getConsumer($consumerName));
		}

		/** @var string|int|NULL $memoryLimit */
		$memoryLimit = $input->getOption('memory-limit');
		if ($memoryLimit !== NULL) {
			Validators::assert($memoryLimit, 'numericint', 'memory limit');
			$memoryLimit = (int) $memoryLimit;
			if ($memoryLimit < 0) {
				throw new \InvalidArgumentException("The -l option should be null or greater than 0");
			}
			$this->consumerRunner->setMemoryLimit($memoryLimit);
		}

		/** @var string|int $idleTimeout */
		$idleTimeout = $input->getOption('idle-timeout');
		Validators::assert($idleTimeout, 'numericint|null', 'idle-timeout option');
		$idleTimeout = (int) $idleTimeout;
		if ($idleTimeout < 0) {
			throw new \InvalidArgumentException("The -t option should be null or greater than 0");
		}
		$this->consumerRunner->setIdleTimeout($idleTimeout);
	}



	protected function execute(InputInterface $input, OutputInterface $output) : int
	{
		$this->consumerRunner->consume($this->amount);
		return 0;
	}



	/**
	 * @internal for pcntl only
	 */
	public function signalTerm() : void
	{
		if ($this->consumerRunner) {
			pcntl_signal(SIGTERM, SIG_DFL);
			$this->consumerRunner->forceStopConsumer();
		}
	}



	/**
	 * @internal for pcntl only
	 */
	public function signalInt() : void
	{
		if ($this->consumerRunner) {
			pcntl_signal(SIGINT, SIG_DFL);
			$this->consumerRunner->forceStopConsumer();
		}
	}



	/**
	 * @internal for pcntl only
	 */
	public function signalHup() : void
	{
		if ($this->consumerRunner) {
			pcntl_signal(SIGHUP, SIG_DFL);
			$this->consumerRunner->forceStopConsumer();
		}
	}

}
