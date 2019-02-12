<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use Nette;
use PhpAmqpLib\Channel\AMQPChannel;



abstract class AmqpMember
{

	use Nette\SmartObject;

	/**
	 * @var Connection
	 */
	protected $connection;

	/**
	 * @var AMQPChannel
	 */
	protected $channel;

	/**
	 * @var string
	 */
	protected $routingKey = '';

	/**
	 * @var bool
	 */
	protected $autoSetupFabric = TRUE;

	/**
	 * @var mixed[]
	 */
	protected $exchangeOptions = [
		'name' => NULL,
		'passive' => FALSE,
		'durable' => TRUE,
		'autoDelete' => FALSE,
		'internal' => FALSE,
		'nowait' => FALSE,
		'arguments' => NULL,
		'ticket' => NULL,
	];

	/**
	 * @var bool
	 */
	protected $exchangeDeclared = FALSE;

	/**
	 * @var mixed[]
	 */
	protected $queueOptions = [
		'name' => '',
		'passive' => FALSE,
		'durable' => TRUE,
		'exclusive' => FALSE,
		'autoDelete' => FALSE,
		'nowait' => FALSE,
		'arguments' => NULL,
		'ticket' => NULL,
		'routing_keys' => [],
	];

	/**
	 * @var bool
	 */
	protected $queueDeclared = FALSE;



	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
	}



	public function __destruct()
	{
		if ($this->channel !== NULL) {
			$this->channel->close();
		}

		if ($this->connection->isConnected()) {
			$this->connection->close();
		}
	}



	public function getChannel() : AMQPChannel
	{
		if ($this->channel === NULL) {
			$this->channel = $this->connection->channel();
		}

		return $this->channel;
	}



	public function setChannel(AMQPChannel $channel) : void
	{
		$this->channel = $channel;
	}



	/**
	 * @param  mixed[] $options
	 */
	public function setExchangeOptions(array $options = []) : void
	{
		if (!isset($options['name'])) {
			throw new \InvalidArgumentException('You must provide an exchange name');
		}

		if (empty($options['type'])) {
			throw new \InvalidArgumentException('You must provide an exchange type');
		}

		$this->exchangeOptions = $options + $this->exchangeOptions;
	}



	/**
	 * @return mixed[]
	 */
	public function getExchangeOptions() : array
	{
		return $this->exchangeOptions;
	}



	/**
	 * @param mixed[] $options
	 */
	public function setQueueOptions(array $options = []) : void
	{
		$this->queueOptions = $options + $this->queueOptions;
	}



	/**
	 * @return mixed[]
	 */
	public function getQueueOptions() : array
	{
		return $this->queueOptions;
	}



	public function setRoutingKey(string $routingKey) : void
	{
		$this->routingKey = $routingKey;
	}



	public function setupFabric() : void
	{
		if (!$this->exchangeDeclared) {
			$this->exchangeDeclare();
		}

		if (!$this->queueDeclared) {
			$this->queueDeclare();
		}
	}



	public function disableAutoSetupFabric() : void
	{
		$this->autoSetupFabric = FALSE;
	}



	protected function exchangeDeclare() : void
	{
		if (empty($this->exchangeOptions['name'])) {
			return;
		}

		$this->getChannel()->exchange_declare(
			$this->exchangeOptions['name'],
			$this->exchangeOptions['type'],
			$this->exchangeOptions['passive'],
			$this->exchangeOptions['durable'],
			$this->exchangeOptions['autoDelete'],
			$this->exchangeOptions['internal'],
			$this->exchangeOptions['nowait'],
			$this->exchangeOptions['arguments'],
			$this->exchangeOptions['ticket']);

		$this->exchangeDeclared = TRUE;
	}



	protected function queueDeclare() : void
	{
		if (empty($this->queueOptions['name'])) {
			return;
		}

		$this->doQueueDeclare($this->queueOptions['name'], $this->queueOptions);
		$this->queueDeclared = TRUE;
	}



	/**
	 * @param string $name
	 * @param mixed[] $options
	 */
	protected function doQueueDeclare(string $name, array $options) : void
	{
		list($queueName, ,) = $this->getChannel()->queue_declare(
			$name,
			$options['passive'],
			$options['durable'],
			$options['exclusive'],
			$options['autoDelete'],
			$options['nowait'],
			$options['arguments'],
			$options['ticket']
		);

		if (empty($options['routing_keys'])) {
			if (!empty($this->exchangeOptions['name'])) {
				$this->getChannel()->queue_bind($queueName, $this->exchangeOptions['name'], $this->routingKey);
			}

		} else {
			foreach ($options['routing_keys'] as $routingKey) {
				$this->getChannel()->queue_bind($queueName, $this->exchangeOptions['name'], $routingKey);
			}
		}
	}

}
