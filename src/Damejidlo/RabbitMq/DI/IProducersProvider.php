<?php

/**
 * This file is part of the Damejidlo (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Damejidlo\RabbitMq\DI;

use Damejidlo;
use Nette;

/**
 * @author Jan Trejbal <jan.trejbal@gmail.com>
 */
interface IProducersProvider
{

	/**
	 * Returns array of name => array config.
	 *
	 * @return array
	 */
	function getRabbitProducers();
}
