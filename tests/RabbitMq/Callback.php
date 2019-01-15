<?php
declare(strict_types = 1);

namespace DamejidloTests\RabbitMq;

class Callback
{

	/**
	 * @var mixed[]
	 */
	public static $accepted = [];



	/**
	 * @param mixed $message
	 */
	public function __invoke($message) : void
	{
		self::$accepted[] = func_get_args();
	}



	/**
	 * @param mixed $message
	 */
	public function process($message) : void
	{
		self::$accepted[] = func_get_args();
	}



	/**
	 * @param mixed $message
	 */
	public static function staticProcess($message) : void
	{
		self::$accepted[] = func_get_args();
	}

}
