<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;



class Producer extends AmqpMember implements IProducer
{

	public const DEFAULT_EXCHANGE_OPTIONS = [
		'passive' => FALSE,
		'durable' => TRUE,
		'autoDelete' => FALSE,
		'internal' => FALSE,
		'nowait' => FALSE,
		'arguments' => NULL,
		'ticket' => NULL,
	];

	/**
	 * @var string
	 */
	protected $exchangeName;

	/**
	 * @var string
	 */
	protected $exchangeType;

	/**
	 * @var string
	 */
	protected $routingKey = '';

	/**
	 * @var string
	 */
	protected $contentType = 'text/plain';

	/**
	 * @var int
	 */
	protected $deliveryMode = AMQPMessage::DELIVERY_MODE_PERSISTENT;

	/**
	 * @var mixed[]
	 */
	protected $exchangeOptions;

	/**
	 * @var bool
	 */
	protected $exchangeDeclared = FALSE;

	/**
	 * @var bool
	 */
	private $autoSetupFabric = TRUE;

	/**
	 * @var bool
	 */
	private $publisherConfirmsEnabled = FALSE;

	/**
	 * @var bool
	 */
	private $publisherConfirmsConfigured = FALSE;



	/**
	 * @param Connection $connection
	 * @param string $exchangeName
	 * @param string $exchangeType
	 * @param string $routingKey
	 * @param mixed[] $exchangeOptions
	 */
	public function __construct(Connection $connection, string $exchangeName, string $exchangeType, string $routingKey = '', array $exchangeOptions = [])
	{
		parent::__construct($connection);
		$this->exchangeName = $exchangeName;
		$this->exchangeType = $exchangeType;
		$this->routingKey = $routingKey;
		$this->exchangeOptions = $exchangeOptions + self::DEFAULT_EXCHANGE_OPTIONS;
	}



	public function setContentType(string $contentType) : void
	{
		$this->contentType = $contentType;
	}



	public function setDeliveryMode(int $deliveryMode) : void
	{
		$this->deliveryMode = $deliveryMode;
	}



	public function disableAutoSetupFabric() : void
	{
		$this->autoSetupFabric = FALSE;
	}



	public function isAutoSetupFabric() : bool
	{
		return $this->autoSetupFabric;
	}



	public function setupFabric() : void
	{
		if ($this->exchangeDeclared) {
			return;
		}

		$this->getChannel()->exchange_declare(
			$this->exchangeName,
			$this->exchangeType,
			$this->exchangeOptions['passive'],
			$this->exchangeOptions['durable'],
			$this->exchangeOptions['autoDelete'],
			$this->exchangeOptions['internal'],
			$this->exchangeOptions['nowait'],
			$this->exchangeOptions['arguments'],
			$this->exchangeOptions['ticket']);

		$this->exchangeDeclared = TRUE;
	}



	public function enablePublisherConfirms() : void
	{
		$this->publisherConfirmsEnabled = TRUE;
	}



	public function isPublisherConfirmsEnabled() : bool
	{
		return $this->publisherConfirmsEnabled;
	}



	public function configurePublisherConfirms() : void
	{
		if ($this->publisherConfirmsConfigured) {
			return;
		}

		$channel = $this->getChannel();

		$channel->set_nack_handler(
			function (AMQPMessage $message) : void {
				throw FailedToPublishMessageException::withExchange($this->exchangeName);
			}
		);

		$channel->set_return_listener(
			function (int $replyCode, string $replyText, string $exchange, string $routingKey, AMQPMessage $message) : void {
				if ($replyText === 'NO_ROUTE') {
					throw UnroutableMessageException::withExchangeAndRoutingKey($exchange, $routingKey);
				}
			}
		);

		$channel->confirm_select();

		$this->publisherConfirmsConfigured = TRUE;
	}



	/**
	 * Publishes the message and merges additional properties with basic properties
	 *
	 * @param string $msgBody
	 * @param string $routingKey if not provided, used default routingKey from configuration of this producer
	 * @param mixed[] $additionalProperties
	 * @throws FailedToPublishMessageException
	 */
	public function publish(string $msgBody, string $routingKey = '', array $additionalProperties = []) : void
	{
		if ($this->isAutoSetupFabric()) {
			$this->setupFabric();
		}

		$isPublisherConfirmsEnabled = $this->isPublisherConfirmsEnabled();
		if ($isPublisherConfirmsEnabled) {
			$this->configurePublisherConfirms();
		}

		if ($routingKey === '') {
			$routingKey = $this->routingKey;
		}

		$channel = $this->getChannel();
		$message = new AMQPMessage($msgBody, array_merge($this->getBasicProperties(), $additionalProperties));
		$channel->basic_publish($message, $this->exchangeName, $routingKey, $isPublisherConfirmsEnabled);

		if ($isPublisherConfirmsEnabled) {
			$channel->wait_for_pending_acks_returns();
		}
	}



	/**
	 * @return mixed[]
	 */
	protected function getBasicProperties() : array
	{
		return ['content_type' => $this->contentType, 'delivery_mode' => $this->deliveryMode];
	}

}
