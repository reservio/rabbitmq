<?php
declare(strict_types = 1);

namespace DamejidloTests\RabbitMq;

use Damejidlo\RabbitMq\Connection;
use Damejidlo\RabbitMq\Producer;
use DamejidloTests\DjTestCase;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';



/**
 * @testCase
 */
class ProducerTest extends DjTestCase
{

	private const EXCHANGE_NAME = 'exchange';
	private const EXCHANGE_TYPE = 'direct';
	private const DEFAULT_ROUTING_KEY = 'default';



	public function testPublishWithDefaultSettings() : void
	{
		$channel = $this->mockChannel();
		$connection = $this->mockConnection($channel);

		$channel->shouldReceive('basic_publish')
			->once()
			->withArgs(
				function (AMQPMessage $message, string $exchangeName, string $routingKey, bool $mandatory) : bool {
					Assert::same('body', $message->getBody());
					Assert::same('bar', $message->get('message_id'));
					Assert::same(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
					Assert::same('text/plain', $message->get('content_type'));
					Assert::same(self::EXCHANGE_NAME, $exchangeName);
					Assert::same('', $routingKey);
					Assert::same(FALSE, $mandatory);

					return TRUE;
				}
			);

		$producer = new Producer($connection, self::EXCHANGE_NAME, self::EXCHANGE_TYPE);
		$producer->disableAutoSetupFabric();

		Assert::noError(function () use ($producer) : void {
			$producer->publish('body', '', ['message_id' => 'bar']);
		});
	}



	public function testPublishWithPublisherConfirms() : void
	{
		$channel = $this->mockChannel();
		$connection = $this->mockConnection($channel);

		$channel->shouldReceive('set_nack_handler')->once();
		$channel->shouldReceive('set_return_listener')->once();
		$channel->shouldReceive('confirm_select')->once();

		$channel->shouldReceive('basic_publish')
			->once()
			->withArgs([AMQPMessage::class, self::EXCHANGE_NAME, '', TRUE]);

		$producer = new Producer($connection, self::EXCHANGE_NAME, self::EXCHANGE_TYPE);
		$producer->disableAutoSetupFabric();
		$producer->enablePublisherConfirms();

		Assert::noError(function () use ($producer) : void {
			$producer->publish('body');
		});
	}



	public function testAutoSetupFabric() : void
	{
		$channel = $this->mockChannel();
		$connection = $this->mockConnection($channel);

		$channel->shouldReceive('exchange_declare')->once();

		$channel->shouldReceive('basic_publish')->twice();

		$producer = new Producer($connection, self::EXCHANGE_NAME, self::EXCHANGE_TYPE);

		Assert::noError(function () use ($producer) : void {
			$producer->publish('body');
			$producer->publish('body');
		});
	}



	public function testDefaultRoutingKeyIsUsed() : void
	{
		$channel = $this->mockChannel();
		$connection = $this->mockConnection($channel);

		$channel->shouldReceive('basic_publish')
			->once()
			->withArgs([AMQPMessage::class, self::EXCHANGE_NAME, self::DEFAULT_ROUTING_KEY, FALSE]);

		$producer = new Producer($connection, self::EXCHANGE_NAME, self::EXCHANGE_TYPE, self::DEFAULT_ROUTING_KEY);
		$producer->disableAutoSetupFabric();

		Assert::noError(function () use ($producer) : void {
			$producer->publish('body', '');
		});
	}



	public function testDefaultRoutingKeyIsOverridden() : void
	{
		$channel = $this->mockChannel();
		$connection = $this->mockConnection($channel);

		$channel->shouldReceive('basic_publish')
			->once()
			->withArgs([AMQPMessage::class, self::EXCHANGE_NAME, 'override', FALSE]);

		$producer = new Producer($connection, self::EXCHANGE_NAME, self::EXCHANGE_TYPE, self::DEFAULT_ROUTING_KEY);
		$producer->disableAutoSetupFabric();

		Assert::noError(function () use ($producer) : void {
			$producer->publish('body', 'override');
		});
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
		$mock->shouldIgnoreMissing();

		return $mock;
	}

}

(new ProducerTest())->run();
