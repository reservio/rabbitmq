<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;



class Producer extends AmqpMember implements IProducer
{

	/**
	 * @var string
	 */
	protected $contentType = 'text/plain';

	/**
	 * @var int
	 */
	protected $deliveryMode = AMQPMessage::DELIVERY_MODE_PERSISTENT;

	/**
	 * @var string
	 */
	protected $routingKey = '';

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



	public function setContentType(string $contentType) : void
	{
		$this->contentType = $contentType;
	}



	public function setDeliveryMode(int $deliveryMode) : void
	{
		$this->deliveryMode = $deliveryMode;
	}



	public function setRoutingKey(string $routingKey) : void
	{
		$this->routingKey = $routingKey;
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
	 * Publishes the message and merges additional properties with basic properties
	 *
	 * @param string $msgBody
	 * @param string $routingKey if not provided, used default routingKey from configuration of this producer
	 * @param mixed[] $additionalProperties
	 */
	public function publish(string $msgBody, string $routingKey = '', array $additionalProperties = []) : void
	{
		if ($this->autoSetupFabric) {
			$this->setupFabric();
		}

		if ($routingKey === '') {
			$routingKey = $this->routingKey;
		}

		$message = new AMQPMessage($msgBody, array_merge($this->getBasicProperties(), $additionalProperties));
		$this->getChannel()->basic_publish($message, $this->exchangeOptions['name'], $routingKey);
	}



	/**
	 * @return mixed[]
	 */
	protected function getBasicProperties() : array
	{
		return ['content_type' => $this->contentType, 'delivery_mode' => $this->deliveryMode];
	}



	public function setupFabric() : void
	{
		if (!$this->exchangeDeclared) {
			$this->exchangeDeclare();
		}
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

}
