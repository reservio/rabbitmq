<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\DI;

interface IProducersProvider
{

	/**
	 * Returns array of name => array config.
	 *
	 * @return mixed[]
	 */
	public function getRabbitProducers() : array;

}
