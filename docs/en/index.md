# Quickstart

This library incorporates messaging in your application via [RabbitMQ](http://www.rabbitmq.com/) using the [php-amqplib](http://github.com/videlalvaro/php-amqplib) library.


## Installation


The best way to install Damejidlo/RabbitMq is using [Composer](http://getcomposer.org/):

```sh
$ composer require damejidlo/rabbitmq
```

Now you have to enable the extension using your neon config

```yml
extensions:
	rabbitmq: Damejidlo\RabbitMq\DI\RabbitMqExtension(%debugMode%)
```

And done!


## Usage

Add the `rabbitmq` section in your configuration file:

```yaml
rabbitmq:
	connection:
		host: localhost
		port: 5672
		user: 'guest'
		password: 'guest'
		vhost: '/'
		heartbeat: 0 # heartbeat is disabled by default
		read_write_timeout: 10.0

	producers:
		uploadPicture:
			connection: default
			exchange: {name: 'upload-picture', type: direct}

	consumers:
		uploadPicture:
			connection: default
			queue: {name: 'upload-picture'}
			callback: [@MyApp\UploadPictureService, processUpload]
			binding:
				- {exchange: 'upload-picture', routingKey: ''}
```

Here we configure the connection service and the message endpoints that our application will have. Connection configured like this will be automatically named `default`.
For the connection, required is only `user` and `password`, others are optional, and the values in the above example are defaults.

In this example your service container will contain the service `rabbitmq.producer.uploadPicture` and `rabbitmq.consumer.uploadPicture`.
The later expects that there's a service of type `App\UploadPictureService` with method `processUpload`, that accepts the `AMQPMessage`.

If you need to add optional queue arguments, then your queue options can be something like this:

```yaml
queue: {name: 'upload-picture', arguments: {'x-ha-policy': ['S', 'all']}}
```

another example with message TTL of 20 seconds:

```yaml
queue: {name: 'upload-picture', arguments: {'x-message-ttl': ['I', 20000]}}
```

The argument value must be a list of datatype and value. Valid datatypes are:

* `S` - String
* `I` - Integer
* `D` - Decimal
* `T` - Timestamps
* `F` - Table
* `A` - Array

Adapt the `arguments` according to your needs.

If you want to bind queue with specific routing keys you can declare it in consumer config:

```yaml
queue:
	name: "upload-picture"
	binding:
	  - {exchange: 'upload-picture-foo', routingKey: 'android.#.upload'}
	  - {exchange: 'upload-picture-bar', routingKey: 'iphone.upload'}
```


## Producers, Consumers, What?

In a messaging application, the process sending messages to the broker is called `producer` while the process receiving those messages is called `consumer`.
In your application you will have several of them that you can list under their respective entries in the configuration.

### Producer

A producer will be used to send messages to the server. In the AMQP Model, messages are sent to an `exchange`,
this means that in the configuration for a producer you will have to specify the connection options along with the exchange options,
which usually will be the name of the exchange and the type of it.

Now let's say that you want to process picture uploads in the background.
After you move the picture to its final location, you will publish a message to server with the following information:

```php
public function actionDefault()
{
	$msg = array('user_id' => 1235, 'image_path' => '/path/to/new/pic.png');

	$producer = $this->serviceLocator->getService('rabbitmq.producer.uploadPicture');
	$producer->publish(serialize($msg));
}
```

As you can see, if in your configuration you have a producer called `uploadPicture`,
then in the service container you will have a service called `rabbitmq.producer.uploadPicture`.
But because it's better to use autowiring, you should use the `Connection` service to get the producer.

```php
/** @var \Damejidlo\RabbitMq\Connection @inject */
public $bunny;

public function actionDefault()
{
	$producer = $this->bunny->getProducer('uploadPicture');
}
```

Besides the message itself, the `Damejidlo\RabbitMq\Producer::publish()` method also accepts an optional routing key parameter and an optional array of additional properties.
The array of additional properties allows you to alter the properties with which an `PhpAmqpLib\Message\AMQPMessage` object gets constructed by default.
This way, for example, you can change the application headers.

You can set default routing key in producer context. You can provide it by `setRoutingKey` method or in configuration like bellow. Default routing key will be used in `publish` method calls with second argument ommited or set to `NULL`. Be aware, that setting second argument to empty string will lead to send empty string as routing key.

```yaml
	...
	producers:
		uploadPicture:
			routingKey: iphone.upload
	...
```

You can use `setContentType` and `setDeliveryMode` methods in order to set the message content type and the message
delivery mode respectively. Default values are `text/plain` for content type and `2` for delivery mode.

```php
$producer->setContentType('application/json');
```

You can also configure these in the config

```yaml
	...
	producers:
		uploadPicture:
			contentType: application/json
			deliveryMode: PhpAmqpLib\Message\AMQPMessage::DELIVERY_MODE_NON_PERSISTENT
	...
```

If you need to use a custom class for a producer (which should inherit from `Damejidlo\RabbitMq\Producer`), you can use the `class` option:

```yaml
	...
	producers:
		uploadPicture:
			class: My\Custom\Producer
	...
```

The next piece of the puzzle is to have a consumer that will take the message out of the queue and process it accordingly.

#### Publisher confirms

To ensure reliable message delivery [publisher confirms](https://www.rabbitmq.com/confirms.html#publisher-confirms) are by default enabled in production mode. If you're uncomfortable with performance hit this brings, you can disable them in your configuration:

```yaml
rabbitmq:
	publisherConfirms: FALSE # enable/disable publisher confirms globally for all producers
	producers:
		uploadPicture:
			publisherConfirms: FALSE # enable/disable publisher confirms for this producer
			...
```


### Consumers

A consumer will connect to the server and start a **loop**  waiting for incoming messages to process.
Depending on the specified `callback` for such consumer will be the behavior it will have.
Let's review the consumer configuration from above:

```yaml
	...
	consumers:
		uploadPicture:
			queue: {name: 'upload-picture'}
			callback: [@MyApp\UploadPictureService, processUpload]
			binding:
				- {exchange: 'upload-picture'}
	...
```

As we see there, the `callback` option has a reference to a service of type `MyApp\UploadPictureService`.
When the consumer gets a message from the server it will execute the callback.
If for testing or debugging purposes you need to specify a different callback, then you can change it there.
The callback service must return a valid response flag (see the constants on `IConsumer`) and should implement the marker interface `IConsumer` (it's optional).

The remaining options are the `binding` and the `queue`.
The `binding` options define the binding of the queue to exchanges, with optional routing key.
In the `queue` options we will provide a **queue name**. Why?

As we said, messages in AMQP are published to an `exchange`. This doesn't mean the message has reached a `queue`.
For this to happen, first we need to create such `queue` and then bind it to the `exchange`.
The cool thing about this is that you can bind several `queues` to one `exchange`, in that way one message can arrive to several destinations.
The advantage of this approach is the **decoupling** from the producer and the consumer.
The producer does not care about how many consumers will process his messages. All it needs is that his message arrives to the server.
In this way we can expand the actions we perform every time a picture is uploaded without the need to change code in our presenter.

Now, how to run a consumer? There's a command for it that can be executed like this:

```bash
$ php www/index.php rabbitmq:consumer -m 50 uploadPicture
```

What does this mean? First of all, we've used [Kdyby/Console](https://github.com/Kdyby/Console/blob/master/docs/en/index.md) here, so have a look at it and then come back.
We are executing the `uploadPicture` consumer telling it to consume only 50 messages.
Every time the consumer receives a message from the server, it will execute the configured callback passing the AMQP message as an instance of the `PhpAmqpLib\Message\AMQPMessage` class.
The message body can be obtained by calling `$msg->body`. By default the consumer will process messages in an **endless loop** for some definition of _endless_.

If you want to be sure that consumer will finish executing instantly on Unix signal, you can run command with flag `-w`.

```bash
$ php www/index.php rabbitmq:consumer -w uploadPicture
```

Then the consumer will finish executing instantly.

For using command with this flag you need to install PHP with [PCNTL extension](http://www.php.net/manual/en/book.pcntl.php).

If you want to establish a consumer memory limit, you can do it by using flag `-l`.
In the following example, this flag adds 256 MB memory limit. Consumer will be stopped five MB before reaching 256MB in order to avoid a PHP Allowed memory size error.

```bash
$ php www/index.php rabbitmq:consumer -l 256 uploadPicture
```

If you need to set a timeout when there are no messages from your queue during a period of time, you can set the idle timeout in seconds:

```bash
$ php www/index.php rabbitmq:consumer -t 60 uploadPicture
```

You can also start a process consuming multiple queues.

```bash
$ php www/index.php rabbitmq:consumer fistConsumerName secondConsumerName thirdConsumerName
```

If you want to remove all the messages awaiting in a queue, you can execute this command to purge this queue:

```bash
$ php www/index.php rabbitmq:purge --no-confirmation uploadPicture
```


#### Fair dispatching

> You might have noticed that the dispatching still doesn't work exactly as we want.
> For example in a situation with two workers, when all odd messages are heavy and even messages are light, one worker will be constantly busy and the other one will do hardly any work.
> Well, RabbitMQ doesn't know anything about that and will still dispatch messages evenly.
>
> This happens because RabbitMQ just dispatches a message when the message enters the queue.
> It doesn't look at the number of unacknowledged messages for a consumer.
> It just blindly dispatches every n-th message to the n-th consumer.
>
> In order to defeat that we can use the basic.qos method with the prefetchCount=1 setting.
> This tells RabbitMQ not to give more than one message to a worker at a time.
> Or, in other words, don't dispatch a new message to a worker until it has processed and acknowledged the previous one.
> Instead, it will dispatch it to the next worker that is not still busy.

From: http://www.rabbitmq.com/tutorials/tutorial-two-python.html

Be careful as implementing the fair dispatching introduce a latency that will hurt performance (see [this blogpost](http://www.rabbitmq.com/blog/2012/05/11/some-queuing-theory-throughput-latency-and-bandwidth/)).
But implementing it allows you to scale horizontally dynamically as the queue is increasing.
You should evaluate, as the blog post recommend, the right value of prefetchSize accordingly with the time taken to process each message and your network performance.

With RabbitMqBundle, you can configure that qos per consumer like that:

```yaml
	...
	consumers:
		uploadPicture:
			qos: {prefetchSize: 0, prefetchCount: 1}
	...
```

### Callbacks

Here's an example callback:

```php
<?php //src/Acme/Consumer/UploadPictureConsumer.php

namespace Acme\Consumer;

use Damejidlo\RabbitMq\IConsumer;
use PhpAmqpLib\Message\AMQPMessage;

class UploadPictureConsumer implements IConsumer
{

	/**
	 * Process picture upload.
	 * $msg will be an instance of `PhpAmqpLib\Message\AMQPMessage` with the $msg->body being the data sent over RabbitMQ.
	 */
	public function process(AMQPMessage $msg) : int
	{
		$isUploadSuccess = $this->someUploadPictureMethod();
		if (!$isUploadSuccess) {
			// If your image upload failed due to a temporary error you can reject & requeue the message
			return IConsumer::MSG_REJECT_REQUEUE;
		}

		return IConsumer::MSG_ACK;
	}

}
```

As you can see, this is as simple as implementing one method: `IConsumer::execute`.

Keep in mind that your callbacks _need to be registered_ as normal services. Then you can inject the database service, business logic classes, logger, and so on.

See [doc/AMQPMessage](https://github.com/videlalvaro/php-amqplib/blob/master/doc/AMQPMessage.md) for more details of what's part of a message instance.


### Recap

This seems to be quite a lot of work for just sending messages, let's recap to have a better overview. This is what we need to produce/consume messages:

- Add an entry for the consumer/producer in the configuration.
- Implement your callback.
- Start the consumer from the CLI.
- Add the code to publish messages inside the presenter.

And that's it!


## Other Commands

### Setting up the RabbitMQ fabric

The purpose of this extension is to let your application produce messages and publish them to some exchanges you configured.

In some cases and even if your configuration is right, the messages you are producing will not be routed to any queue because none exist.
The consumer responsible for the queue consumption has to be run for the queue to be created.

Launching a command for each consumer can be a nightmare when the number of consumers is high.

In order to create exchanges, queues and bindings at once and be sure you will not lose any message, you can run the following command:

```bash
$ php www/index.php rabbitmq:setup-fabric
```

When desired, you can configure your consumers and producers to assume the RabbitMQ fabric is already defined. To do this, add the following to your configuration:

```yaml
	...
	producers:
		uploadPicture:
			autoSetupFabric: off

	consumers:
		uploadPicture:
			autoSetupFabric: off
	...
```

By default a consumer or producer will declare everything it needs with RabbitMQ when it starts.
Be careful using this, when exchanges or queues are not defined, there will be errors.
When you've changed any configuration you need to run the above setup-fabric command to declare your configuration.
It might also be a good idea to always call the `rabbitmq:setup-fabric` command on every deploy.
