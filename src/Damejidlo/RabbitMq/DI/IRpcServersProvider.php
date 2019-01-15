<?php

namespace Damejidlo\RabbitMq\DI;

use Damejidlo;
use Nette;

/**
 * @author Jan Trejbal <jan.trejbal@gmail.com>
 */
interface IRpcServersProvider
{

	/**
	 * Returns array of name => array config.
	 *
	 * @return array
	 */
	function getRabbitRpcServers();
}
