<?php
declare(strict_types = 1);

namespace DamejidloTests\RabbitMq;

use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Consumer;
use Damejidlo\RabbitMq\IConsumer;
use DamejidloTests\DjTestCase;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';



/**
 * @testCase
 */
class ConsumerTest extends DjTestCase
{

	/**
	 * Check if the message is requeued or not correctly.
	 *
	 * @dataProvider processMessageProvider
	 * @param bool|int|NULL $processFlag
	 * @param string $expectedMethod
	 * @param bool|NULL $expectedRequeue
	 */
	public function testProcessMessage($processFlag, string $expectedMethod, ?bool $expectedRequeue = NULL) : void
	{
		$connection = $this->mockConnection();
		$channel = $this->mockChannel();

		$consumer = new Consumer($connection);
		$consumer->setChannel($channel);

		// Create a callback function with a return value set by the data provider.
		$callbackFunction = function () use ($processFlag) {
			return $processFlag;
		};
		$consumer->setCallback($callbackFunction);

		// Create a default message
		$message = new AMQPMessage('foo body');
		$message->delivery_info['channel'] = $channel;
		$message->delivery_info['delivery_tag'] = 0;

		$channel->shouldReceive('basic_reject')
			->andReturnUsing(function ($delivery_tag, $requeue) use ($expectedMethod, $expectedRequeue) : void {
				Assert::same($expectedMethod, 'basic_reject'); // Check if this function should be called.
				Assert::same($requeue, $expectedRequeue); // Check if the message should be requeued.
			});

		$channel->shouldReceive('basic_ack')
			->andReturnUsing(function ($delivery_tag) use ($expectedMethod) : void {
				Assert::same($expectedMethod, 'basic_ack'); // Check if this function should be called.
			});

		$consumer->processMessage($message);
	}



	/**
	 * @return mixed[]
	 */
	protected function processMessageProvider() : array
	{
		return [
			[NULL, 'basic_ack'], // Remove message from queue only if callback return not false
			[TRUE, 'basic_ack'], // Remove message from queue only if callback return not false
			[FALSE, 'basic_reject', TRUE], // Reject and requeue message to RabbitMQ
			[IConsumer::MSG_ACK, 'basic_ack'], // Remove message from queue only if callback return not false
			[IConsumer::MSG_REJECT_REQUEUE, 'basic_reject', TRUE], // Reject and requeue message to RabbitMQ
			[IConsumer::MSG_REJECT, 'basic_reject', FALSE], // Reject and drop
		];
	}



	/**
	 * @return Connection|MockInterface
	 */
	private function mockConnection() : Connection
	{
		$mock = \Mockery::mock(Connection::class);
		$mock->makePartial();

		return $mock;
	}



	/**
	 * @return AMQPChannel|MockInterface
	 */
	private function mockChannel() : AMQPChannel
	{
		$mock = \Mockery::mock(AMQPChannel::class);
		$mock->makePartial();

		return $mock;
	}

}

(new ConsumerTest())->run();
