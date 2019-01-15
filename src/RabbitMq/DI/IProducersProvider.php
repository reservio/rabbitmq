<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq\DI;

/**
 * @author Jan Trejbal <jan.trejbal@gmail.com>
 */
interface IProducersProvider
{

	/**
	 * Returns array of name => array config.
	 *
	 * @return mixed[]
	 */
	public function getRabbitProducers() : array;

}
