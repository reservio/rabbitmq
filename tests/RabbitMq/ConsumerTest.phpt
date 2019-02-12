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
	 * @param int $processFlag
	 * @param string $expectedMethod
	 * @param bool|NULL $expectedRequeue
	 */
	public function testProcessMessage(int $processFlag, string $expectedMethod, ?bool $expectedRequeue = NULL) : void
	{
		$channel = $this->mockChannel();
		$connection = $this->mockConnection($channel);

		$consumer = new Consumer($connection);

		// Create a callback function with a return value set by the data provider.
		$callbackFunction = function () use ($processFlag) : int {
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

		$channel->shouldReceive('basic_nack')
			->andReturnUsing(function ($delivery_tag, $multiple, $requeue) use ($expectedMethod, $expectedRequeue) : void {
				Assert::same($expectedMethod, 'basic_nack'); // Check if this function should be called.
				Assert::same($requeue, $expectedRequeue); // Check if the message should be requeued.
			});

		$channel->shouldReceive('basic_ack')
			->andReturnUsing(function ($delivery_tag) use ($expectedMethod) : void {
				Assert::same($expectedMethod, 'basic_ack'); // Check if this function should be called.
			});

		$consumer->processMessage($message);
	}



	public function testInvalidResponse() : void
	{
		$channel = $this->mockChannel();
		$connection = $this->mockConnection($channel);

		$consumer = new Consumer($connection);

		$callbackFunction = function () : int {
			return 666;
		};
		$consumer->setCallback($callbackFunction);

		// Create a default message
		$message = new AMQPMessage('foo body');
		$message->delivery_info['channel'] = $channel;
		$message->delivery_info['delivery_tag'] = 0;

		Assert::exception(
			function () use ($consumer, $message) : void {
				$consumer->processMessage($message);
			},
			\InvalidArgumentException::class,
			"Invalid response flag '666'."
		);
	}



	/**
	 * @return mixed[]
	 */
	protected function processMessageProvider() : array
	{
		return [
			[IConsumer::MSG_ACK, 'basic_ack'],
			[IConsumer::MSG_REJECT_REQUEUE, 'basic_reject', TRUE],
			[IConsumer::MSG_REJECT, 'basic_reject', FALSE],
			[IConsumer::MSG_SINGLE_NACK_REQUEUE, 'basic_nack', TRUE],
		];
	}



	/**
	 * @param AMQPChannel $channel
	 * @return Connection|MockInterface
	 */
	private function mockConnection(AMQPChannel $channel) : Connection
	{
		$mock = \Mockery::mock(Connection::class);
		$mock->makePartial();
		$mock->shouldReceive('channel')->andReturn($channel);

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
