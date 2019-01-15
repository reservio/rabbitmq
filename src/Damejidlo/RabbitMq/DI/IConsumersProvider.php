<?php

namespace Damejidlo\RabbitMq\DI;

use Damejidlo;
use Nette;

/**
 * @author Jan Trejbal <jan.trejbal@gmail.com>
 */
interface IConsumersProvider
{

	/**
	 * Returns array of name => array config.
	 *
	 * @return array
	 */
	function getRabbitConsumers();
}
