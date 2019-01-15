<?php

namespace Damejidlo\RabbitMq;

use Damejidlo;
use Nette;



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
interface IConnection
{

	/**
	 * @param string $name
	 * @return BaseConsumer
	 */
	function getConsumer($name);



	/**
	 * @param $name
	 * @return Producer
	 */
	function getProducer($name);

}
