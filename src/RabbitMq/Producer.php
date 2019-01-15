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



	public function setContentType(string $contentType) : void
	{
		$this->contentType = $contentType;
	}



	public function setDeliveryMode(int $deliveryMode) : void
	{
		$this->deliveryMode = $deliveryMode;
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

}
