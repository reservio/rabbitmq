<?php
declare(strict_types = 1);

namespace Damejidlo\RabbitMq;

interface IConsumerRunnerFactory
{

	public function create() : ConsumerRunner;

}
