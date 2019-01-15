<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

interface IProducer
{

	/**
	 * @param mixed[] $options
	 */
	public function setExchangeOptions(array $options = []) : void;



	/**
	 * @param mixed[] $options
	 */
	public function setQueueOptions(array $options = []) : void;



	public function setRoutingKey(string $routingKey) : void;



	public function setContentType(string $contentType) : void;



	public function setDeliveryMode(int $deliveryMode) : void;



	/**
	 * @param string $msgBody
	 * @param string $routingKey
	 * @param mixed[] $additionalProperties
	 */
	public function publish(string $msgBody, string $routingKey = '', array $additionalProperties = []) : void;

}
