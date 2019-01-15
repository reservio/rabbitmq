<?php

namespace Damejidlo\RabbitMq;

use Damejidlo;
use Nette;



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
