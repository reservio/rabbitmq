<?php
declare(strict_types = 1);

namespace DamejidloTests\RabbitMq;

use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use DamejidloTests\DjTestCase;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';



/**
 * @testCase
 */
class BaseAmqpTest extends DjTestCase
{

	public function testLazyConnection() : void
	{
		$lazyConnection = new Connection('localhost', '123', 'lazy_user', 'lazy_password');
		$consumer = new Consumer($lazyConnection, 'queue', new Callback());

		Assert::exception(function () use ($consumer) : void {
			$consumer->getChannel();
		}, \ErrorException::class, 'stream_socket_client(): unable to connect to tcp://localhost:123 (%a%)');
	}

}

(new BaseAmqpTest())->run();
