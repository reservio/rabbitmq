<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\DI;

interface IConsumersProvider
{

	/**
	 * Returns array of name => array config.
	 *
	 * @return mixed[]
	 */
	public function getRabbitConsumers() : array;

}
