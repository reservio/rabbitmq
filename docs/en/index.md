# Quickstart

This library incorporates messaging in your application via [RabbitMQ](http://www.rabbitmq.com/) using the [php-amqplib](http://github.com/videlalvaro/php-amqplib) library.

The bundle implements several messaging patterns as seen on the [Thumper](https://github.com/videlalvaro/Thumper) library.
Therefore publishing messages to RabbitMQ from a presenter is as easy as:

```php
/** @var Kdyby\RabbitMq\Connection @inject */
public $connection;

public function actionDefault()
{
	$msg = array('user_id' => 1235, 'image_path' => '/path/to/new/pic.png');
	$this->connection->getProducer('upload_picture')
		->publish(serialize($msg));
}
```

Later when you want to consume 50 messages out of the `upload_pictures` queue, you just run on the CLI:

```bash
$ ./app/console rabbitmq:consumer -m 50 upload_picture
```

All the examples expect a running RabbitMQ server.

## Installation



## Usage ##

Add the `old_sound_rabbit_mq` section in your configuration file:

```yaml
old_sound_rabbit_mq:
    connections:
        default:
            host:     'localhost'
            port:     5672
            user:     'guest'
            password: 'guest'
            vhost:    '/'

    producers:
        upload_picture:
            connection:       default
            exchange: {name: 'upload-picture', type: direct}

    consumers:
        upload_picture:
            connection:       default
            exchange: {name: 'upload-picture', type: direct}
            queue:    {name: 'upload-picture'}
            callback:         upload_picture_service
```

Here we configure the connection service and the message endpoints that our application will have. In this example your service container will contain the service `old_sound_rabbit_mq.upload_picture_producer` and `old_sound_rabbit_mq.upload_picture_consumer`. The later expects that there's a service called `upload_picture_service`.

If you don't specify a connection for the client, the client will look for a connection with the same alias. So for our `upload_picture` the service container will look for an `upload_picture` connection.

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

If you want to bind queue with specific routing keys you can declare it in producer or consumer config:

```yaml
queue:
    name: "upload-picture"
    routingKeys:
      - 'android.#.upload'
      - 'iphone.upload'
```

### Important notice - Lazy Connections ###

In current Symfony release (v2.3) all services are fully bootstrapped for each request, a lazy loading support
will be added in the future to save resources. Services in this bundle, by default, will open connections
to the brokers at loading time, i.e. during every web request, unless you set `lazy: true` in your connection configuration.
It's extremely recommended to use lazy connections because performance reasons, nevertheless lazy option is disabled
by default to avoid possible breaks in applications already using this bundle.

## Producers, Consumers, What? ##

In a messaging application, the process sending messages to the broker is called __producer__ while the process receiving those messages is called __consumer__. In your application you will have several of them that you can list under their respective entries in the configuration.

### Producer ###

A producer will be used to send messages to the server. In the AMQP Model, messages are sent to an __exchange__, this means that in the configuration for a producer you will have to specify the connection options along with the exchange options, which usually will be the name of the exchange and the type of it.

Now let's say that you want to process picture uploads in the background. After you move the picture to its final location, you will publish a message to server with the following information:

```php
public function indexAction($name)
{
    $msg = array('user_id' => 1235, 'image_path' => '/path/to/new/pic.png');
    $this->get('old_sound_rabbit_mq.upload_picture_producer')->publish(serialize($msg));
}
```

As you can see, if in your configuration you have a producer called __upload\_picture__, then in the service container you will have a service called __old_sound_rabbit_mq.upload\_picture\_producer__.

Besides the message itself, the `OldSound\RabbitMqBundle\RabbitMq\Producer#publish()` method also accepts an optional routing key parameter and an optional array of additional properties. The array of additional properties allows you to alter the properties with which an `PhpAmqpLib\Message\AMQPMessage` object gets constructed by default. This way, for example, you can change the application headers.

You can use __setContentType__ and __setDeliveryMode__ methods in order to set the message content type and the message
delivery mode respectively. Default values are __text/plain__ for content type and __2__ for delivery mode.

```php
$this->get('old_sound_rabbit_mq.upload_picture_producer')->setContentType('application/json');
```

If you need to use a custom class for a producer (which should inherit from `OldSound\RabbitMqBundle\RabbitMq\Producer`), you can use the `class` option:

```yaml
    ...
    producers:
        upload_picture:
            class: My\Custom\Producer
            connection: default
            exchange: {name: 'upload-picture', type: direct}
    ...
```


The next piece of the puzzle is to have a consumer that will take the message out of the queue and process it accordingly.

### Consumers ###

A consumer will connect to the server and start a __loop__  waiting for incoming messages to process. Depending on the specified __callback__ for such consumer will be the behavior it will have. Let's review the consumer configuration from above:

```yaml
consumers:
    upload_picture:
        connection:       default
        exchange: {name: 'upload-picture', type: direct}
        queue:    {name: 'upload-picture'}
        callback:         upload_picture_service
```

As we see there, the __callback__ option has a reference to an __upload\_picture\_service__. When the consumer gets a message from the server it will execute such callback. If for testing or debugging purposes you need to specify a different callback, then you can change it there.

Apart from the callback we also specify the connection to use, the same way as we do with a __producer__. The remaining options are the __exchange\_options__ and the __queue\_options__. The __exchange\_options__ should be the same ones as those used for the __producer__. In the __queue\_options__ we will provide a __queue name__. Why?

As we said, messages in AMQP are published to an __exchange__. This doesn't mean the message has reached a __queue__. For this to happen, first we need to create such __queue__ and then bind it to the __exchange__. The cool thing about this is that you can bind several __queues__ to one __exchange__, in that way one message can arrive to several destinations. The advantage of this approach is the __decoupling__ from the producer and the consumer. The producer does not care about how many consumers will process his messages. All it needs is that his message arrives to the server. In this way we can expand the actions we perform every time a picture is uploaded without the need to change code in our controller.

Now, how to run a consumer? There's a command for it that can be executed like this:

```bash
$ ./app/console rabbitmq:consumer -m 50 upload_picture
```

What does this mean? We are executing the __upload\_picture__ consumer telling it to consume only 50 messages. Every time the consumer receives a message from the server, it will execute the configured callback passing the AMQP message as an instance of the `PhpAmqpLib\Message\AMQPMessage` class. The message body can be obtained by calling `$msg->body`. By default the consumer will process messages in an __endless loop__ for some definition of _endless_.

If you want to be sure that consumer will finish executing instantly on Unix signal, you can run command with flag `-w`.

```bash
$ ./app/console rabbitmq:consumer -w upload_picture
```

Then the consumer will finish executing instantly.

For using command with this flag you need to install PHP with [PCNTL extension](http://www.php.net/manual/en/book.pcntl.php).

If you want to establish a consumer memory limit, you can do it by using flag `-l`. In the following example, this flag adds 256 MB memory limit. Consumer will be stopped five MB before reaching 256MB in order to avoid a PHP Allowed memory size error.

```bash
$ ./app/console rabbitmq:consumer -l 256
```

If you want to remove all the messages awaiting in a queue, you can execute this command to purge this queue:

```bash
$ ./app/console rabbitmq:purge --no-confirmation upload_picture
```

#### Idle timeout ####

If you need to set a timeout when there are no messages from your queue during a period of time, you can set the `idleTimeout` in seconds:

```yaml
consumers:
    upload_picture:
        connection:       default
        exchange: {name: 'upload-picture', type: direct}
        queue:    {name: 'upload-picture'}
        callback:         upload_picture_service
        idleTimeout:     60
```

#### Fair dispatching ####

> You might have noticed that the dispatching still doesn't work exactly as we want. For example in a situation with two workers, when all odd messages are heavy and even messages are light, one worker will be constantly busy and the other one will do hardly any work. Well, RabbitMQ doesn't know anything about that and will still dispatch messages evenly.

> This happens because RabbitMQ just dispatches a message when the message enters the queue. It doesn't look at the number of unacknowledged messages for a consumer. It just blindly dispatches every n-th message to the n-th consumer.

> In order to defeat that we can use the basic.qos method with the prefetchCount=1 setting. This tells RabbitMQ not to give more than one message to a worker at a time. Or, in other words, don't dispatch a new message to a worker until it has processed and acknowledged the previous one. Instead, it will dispatch it to the next worker that is not still busy.

From: http://www.rabbitmq.com/tutorials/tutorial-two-python.html

Be careful as implementing the fair dispatching introduce a latency that will hurt performance (see [this blogpost](http://www.rabbitmq.com/blog/2012/05/11/some-queuing-theory-throughput-latency-and-bandwidth/)). But implemeting it allow you to scale horizontally dynamically as the queue is increasing. 
You should evaluate, as the blogpost reccommand, the right value of prefetchSize accordingly with the time taken to process each message and your network performance.

With RabbitMqBundle, you can configure that qos per consumer like that:

```yaml
consumers:
    upload_picture:
        connection:       default
        exchange: {name: 'upload-picture', type: direct}
        queue:    {name: 'upload-picture'}
        callback:         upload_picture_service
        qos:      {prefetchSize: 0, prefetchCount: 1, global: false}
```

### Callbacks ###

Here's an example callback:

```php
<?php

//src/Acme/DemoBundle/Consumer/UploadPictureConsumer.php

namespace Acme\DemoBundle\Consumer;

use OldSound\RabbitMqBundle\RabbitMq\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class UploadPictureConsumer implements ConsumerInterface
{
    public function execute(AMQPMessage $msg)
    {
        //Process picture upload.
        //$msg will be an instance of `PhpAmqpLib\Message\AMQPMessage` with the $msg->body being the data sent over RabbitMQ.

        $isUploadSuccess = someUploadPictureMethod();
        if (!$isUploadSuccess) {
            // If your image upload failed due to a temporary error you can return false
            // from your callback so the message will be rejected by the consumer and
            // requeued by RabbitMQ.
            // Any other value not equal to false will acknowledge the message and remove it
            // from the queue
            return false;
        }
    }
}
```

As you can see, this is as simple as implementing one method: __ConsumerInterface::execute__.

Keep in mind that your callbacks _need to be registered_ as normal Symfony2 services. There you can inject the service container, the database service, the Symfony logger, and so on.

See [https://github.com/videlalvaro/php-amqplib/blob/master/doc/AMQPMessage.md](https://github.com/videlalvaro/php-amqplib/blob/master/doc/AMQPMessage.md) for more details of what's part of a message instance.

### Recap ###

This seems to be quite a lot of work for just sending messages, let's recap to have a better overview. This is what we need to produce/consume messages:

- Add an entry for the consumer/producer in the configuration.
- Implement your callback.
- Start the consumer from the CLI.
- Add the code to publish messages inside the controller.

And that's it!

### RPC or Reply/Response ###

So far we just have sent messages to consumers, but what if we want to get a reply from them? To achieve this we have to implement RPC calls into our application. This bundle makes is pretty easy to achieve such things with Symfony2.

Let's add a RPC client and server into the configuration:

```yaml
rpcClients:
    integer_store:
        connection: default
rpcServers:
    random_int:
        connection: default
        callback:   random_int_server
        qos: {prefetchSize: 0, prefetchCount: 1, global: false}
```

Here we have a very useful server: it returns random integers to its clients. The callback used to process the request will be the __random\_int\_server__ service. Now let's see how to invoke it from our controllers.

First we have to start the server from the command line:

```bash
$ ./app/console_dev rabbitmq:rpc-server random_int
```

And then add the following code to our controller:

```php
public function indexAction($name)
{
    $client = $this->get('old_sound_rabbit_mq.integer_store_rpc');
    $client->addRequest(serialize(array('min' => 0, 'max' => 10)), 'random_int', 'request_id');
    $replies = $client->getReplies();
}
```

As you can see there, if our client id is __integer\_store__, then the service name will be __old_sound_rabbit_mq.integer\_store_rpc__. Once we get that object we place a request on the server by calling `addRequest` that expects three parameters:

- The arguments to be sent to the remote procedure call.
- The name of the RPC server, in our case __random\_int__.
- A request identifier for our call, in this case __request\_id__.

The arguments we are sending are the __min__ and __max__ values for the `rand()` function. We send them by serializing an array. If our server expects JSON information, or XML, we will send such data here.

The final piece is to get the reply. Our PHP script will block till the server returns a value. The __$replies__ variable will be an associative array where each reply from the server will contained in the respective __request\_id__ key.

By default the RCP Client expects the response to be serialized. If the server you are working with returns a non-serialized result then set the RPC client expect_serialized_response option to false. For example, if the integer_store server didn't serialize the result the client would be set as below:

```yaml
rpcClients:
    integer_store:
        connection: default
        expect_serialized_response: false
```

You can also set a expiration for request in seconds, after which message will no longer be handled by server and client request will simply time out. Setting expiration for messages works only for RabbitMQ 3.x and above. Visit http://www.rabbitmq.com/ttl.html#per-message-ttl for more information.

```php
public function indexAction($name)
{
    $expiration = 5; // seconds
    $client = $this->get('old_sound_rabbit_mq.integer_store_rpc');
    $client->addRequest($body, $server, $requestId, $expiration);
    try {
        $replies = $client->getReplies();
        // process $replies['request_id'];
    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
        // handle timeout
    }
}
```

As you can guess, we can also make __parallel RPC calls__.

### Parallel RPC ###

Let's say that for rendering some webpage, you need to perform two database queries, one taking 5 seconds to complete and the other one taking 2 seconds –very expensive queries–. If you execute them sequentially, then your page will be ready to deliver in about 7 seconds. If you run them in parallel then you will have your page served in about 5 seconds. With RabbitMqBundle we can do such parallel calls with ease. Let's define a parallel client in the config and another RPC server:

```yaml
rpcClients:
    parallel:
        connection: default
rpcServers:
    char_count:
        connection: default
        callback:   char_count_server
    random_int:
        connection: default
        callback:   random_int_server
```

Then this code should go in our controller:

```php
public function indexAction($name)
{
    $client = $this->get('old_sound_rabbit_mq.parallel_rpc');
    $client->addRequest($name, 'char_count', 'char_count');
    $client->addRequest(serialize(array('min' => 0, 'max' => 10)), 'random_int', 'random_int');
    $replies = $client->getReplies();
}
```

Is very similar to the previous example, we just have an extra `addRequest` call. Also we provide meaningful request identifiers so later will be easier for us to find the reply we want in the __$replies__ array.

### Multiple Consumers ###

It's a good practice to have a lot of queues for logic separation. With a simple consumer you will have to create one worker (consumer) per queue and it can be hard to manage when dealing
with many evolutions (forget to add a line in your supervisord configuration?). This is also useful for small queues as you may not want to have as many workers as queues, and want to regroup
some tasks together without losing flexibility and separation principle.

Multiple consumers allow you to handle this use case by listening to multiple queues on the same consumer.

Here is how you can set a consumer with multiple queues:

```yaml
multiple_consumers:
    upload:
        connection:       default
        exchange: {name: 'upload', type: direct}
        queues:
            upload-picture:
                name:     upload_picture
                callback: upload_picture_service
                routingKeys:
                    - picture
            upload-video:
                name:     upload_video
                callback: upload_video_service
                routingKeys:
                    - video
            upload-stats:
                name:     upload_stats
                callback: upload_stats
```

The callback is now specified under each queues and must implement the `ConsumerInterface` like a simple consumer.
All the options of `queues-options` in the consumer are available for each queue.

Be aware that all queues are under the same exchange, it's up to you to set the correct routing for callbacks.

### Anonymous Consumers ###

Now, why will we ever need anonymous consumers? This sounds like some internet threat or something… Keep reading.

In AMQP there's a type of exchange called __topic__ where the messages are routed to queues based on –you guess– the topic of the message. We can send logs about our application to a RabbiMQ topic exchange using as topic the hostname where the log was created and the severity of such log. The message body will be the log content and our routing keys the will be like this:

- server1.error
- server2.info
- server1.warning
- ...

Since we don't want to be filling up queues with unlimited logs what we can do is that when we want to monitor the system, we can launch a consumer that creates a queue and attaches to the __logs__ exchange based on some topic, for example, we would like to see all the errors reported by our servers. The routing key will be something like: __\#.error__. In such case we have to come up with a queue name, bind it to the exchange, get the logs, unbind it and delete the queue. Happily AMPQ provides a way to do this automatically if you provide the right options when you declare and bind the queue. The problem is that you don't want to remember all those options. For such reason we implemented the __Anonymous Consumer__ pattern.

When we start an Anonymous Consumer, it will take care of such details and we just have to think about implementing the callback for when the messages arrive. Is it called Anonymous because it won't specify a queue name, but it will wait for RabbitMQ to assign a random one to it.

Now, how to configure and run such consumer?

```yaml
anon_consumers:
    logs_watcher:
        connection:       default
        exchange: {name: 'app-logs', type: topic}
        callback:         logs_watcher
```

There we specify the exchange name and it's type along with the callback that should be executed when a message arrives.

This Anonymous Consumer is now able to listen to Producers, which are linked to the same exchange and of type _topic_:

```yaml
    producers:
        app_logs:
            connection:       default
            exchange: {name: 'app-logs', type: topic}
```

To start an _Anonymous Consumer_ we use the following command:

```bash
$ ./app/console_dev rabbitmq:anon-consumer -m 5 -r '#.error' logs_watcher
```

The only new option compared to the commands that we have seen before is the one that specifies the __routing key__: `-r '#.error'`.

### STDIN Producer ###

There's a Command that reads data from STDIN and publishes it to a RabbitMQ queue. To use it first you have to configure a `producer` service in your configuration file like this:

```yaml
producers:
    words:
      connection:       default
      exchange: {name: 'words', type: direct}
```

That producer will publish messages to the `words` direct exchange. Of course you can adapt the configuration to whatever you like.

Then let's say you want to publish the contents of some XML files so they are processed by a farm of consumers. You could publish them by just using a command like this:

```bash
$ find vendor/symfony/ -name "*.xml" -print0 | xargs -0 cat | ./app/console rabbitmq:stdin-producer words
```

This means you can compose producers with plain Unix commands.

Let's decompose that one liner:

```bash
$ find vendor/symfony/ -name "*.xml" -print0
```

That command will find all the `.xml` files inside the symfony folder and will print the file name. Each of those file names is then _piped_ to `cat` via `xargs`:

```bash
$ xargs -0 cat
```

And finally the output of `cat` goes directly to our producer that is invoked like this:

```bash
$ ./app/console rabbitmq:stdin-producer words
```

It takes only one argument which is the name of the producer as you configured it in your `config.yml` file.

## Other Commands ##

### Setting up the RabbitMQ fabric ###

The purpose of this bundle is to let your application produce messages and publish them to some exchanges you configured.

In some cases and even if your configuration is right, the messages you are producing will not be routed to any queue because none exist. The consumer responsible for the queue consumption has to be run for the queue to be created.

Launching a command for each consumer can be a nightmare when the number of consumers is high.

In order to create exchanges, queues and bindings at once and be sure you will not lose any message, you can run the following command:

```bash
$ ./app/console rabbitmq:setup-fabric
```

When desired, you can configure your consumers and producers to assume the RabbitMQ fabric is already defined. To do this, add the following to your configuration:

```yaml
producers:
    upload_picture:
      autoSetupFabric: false
consumers:
    upload_picture:
      autoSetupFabric: false
```

By default a consumer or producer will declare everything it needs with RabbitMQ when it starts.
Be careful using this, when exchanges or queues are not defined, there will be errors. When you've changed any configuration you need to run the above setup-fabric command to declare your configuration.
