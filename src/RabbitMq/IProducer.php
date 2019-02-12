<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

interface IProducer
{

	/**
	 * @param string $msgBody
	 * @param string $routingKey
	 * @param mixed[] $additionalProperties
	 */
	public function publish(string $msgBody, string $routingKey = '', array $additionalProperties = []) : void;

}
