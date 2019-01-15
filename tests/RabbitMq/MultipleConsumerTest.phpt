<?php
declare(strict_types = 1);

namespace DamejidloTests\RabbitMq;

use Damejidlo\RabbitMq\Channel;
use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\IConsumer;
use Damejidlo\RabbitMq\MultipleConsumer;
use DamejidloTests\DjTestCase;
use Mockery\MockInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';



/**
 * @testCase
 */
class MultipleConsumerTest extends DjTestCase
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

		$consumer = new MultipleConsumer($connection);
		$consumer->setChannel($channel);

		$callback = function ($msg) use ($processFlag) {
			return $processFlag;
		};
		$consumer->setQueues(['test-1' => ['callback' => $callback], 'test-2' => ['callback' => $callback]]);

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

		$consumer->processQueueMessage('test-1', $message);
		$consumer->processQueueMessage('test-2', $message);
	}



	/**
	 * @return mixed[]
	 */
	public function processMessageProvider() : array
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
	 * @return Channel|MockInterface
	 */
	private function mockChannel() : Channel
	{
		$mock = \Mockery::mock(Channel::class);
		$mock->makePartial();

		return $mock;
	}

}

(new MultipleConsumerTest())->run();
