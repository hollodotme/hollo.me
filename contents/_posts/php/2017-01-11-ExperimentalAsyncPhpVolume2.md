---
layout: posts
title: Experimental async PHP vol. 2
tags: [async, experimental, PHP, code, rabbitmq, OSS]
permalink: /php/experimental-async-php-volume-2.html
slug: php
---
## Updates

* 2017-03-07: Code updated for [v2.1.0 of hollodotme/fast-cgi-client](https://github.com/hollodotme/fast-cgi-client/tree/v2.1.0).
* 2017-09-11: Q&A: [Error handling and retry](/php/experimental-async-php-volume-2-error-handling.html)
* 2017-09-17: Q&A: [Parallelism](/php/experimental-async-php-volume-2-parallelism.html)

---

## Preamble

Taking the topic of my [previous post](/php/experimental-async-php-volume-1.html) further, it is time to (hopefully) eliminate some drawbacks
that came along in the first try, such as:

 * Redis has no message backlog. If the "Daemon" is not running for any reason, published messages will never be received.
 * Redis has a [publishing complexity](https://redis.io/commands/publish) of "`O(N+M)` where N is the number of clients subscribed to the receiving channel and M is the total number of subscribed patterns (by any client)." 
    So all "Daemons" (if multiple) would get the same messages and trigger the same "Workers". 

So let's change the goal a bit and replace Redis with a real message broker: [RabbitMQ](https://www.rabbitmq.com).

## Goal

* A PHP script ("Caller") sending messages to the RabbitMQ message queue system ("Broker")
* A PHP script ("Daemon") running as a proper daemon consuming messages from the queue 
* On consuming a message the "Daemon" sends a new async request through php-fpm socket to the "Worker"
* The php-fpm socket serves as an "isolated" pool and spawns child processes
* The "Workers" process the requests in background 

[![Caller-RabbitMQ-Daemon-Socket-Worker](/assets/img/posts/caller-rabbitmq-daemon-socket-worker.png)](/assets/img/posts/caller-rabbitmq-daemon-socket-worker.png)

---

## Used environment

* OS: Ubuntu Xenial 16.04.1 LTS
* PHP 7.1.0-3+deb.sury.org~xenial+1
* [RabbitMQ Server 3.6.6](https://www.rabbitmq.com/install-debian.html)
* [composer PHP dependency manager](https://getcomposer.org)
* <i class="fa fa-github"></i> [hollodotme/fast-cgi-client](https://github.com/hollodotme/fast-cgi-client)
* <i class="fa fa-github"></i> [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib)

---


## The "Caller" version #1

Again, the "Caller" is a simple script, that sends a message to the "Broker", to a queue named "commands".
To be a little more verbose we'll provide a counter as an argument to the script that will be the content of the message.

<i class="fa fa-file-o"></i> `src/caller.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

require(__DIR__ . '/../vendor/autoload.php');

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

# Connect and retrieve a channel
$connection = new AMQPStreamConnection( 'localhost', 5672, 'guest', 'guest' );
$channel    = $connection->channel();

# Make sure the queue 'commands' exist
$channel->queue_declare( 'commands' );

# Create and send the message
$message = new AMQPMessage( json_encode( [ 'number' => $argv[1] ], JSON_PRETTY_PRINT ) );
$channel->basic_publish( $message, '', 'commands' );

echo " [x] Message sent: {$argv[1]}\n";

# Close channel and connection
$channel->close();
$connection->close();
```

---

## The "Daemon" version #1

Also in the "Daemon" we replace the Redis subscription with a basic consumption of messages sent to the "commands" queue of the "Broker".

When a message is consumed a callback function (Closure) will be invoked. 
This Closure will again send a request to our previously set up php-fpm pool, thus to our "Workers".

<i class="fa fa-file-o"></i> `src/daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\PostRequest;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require(__DIR__ . '/../vendor/autoload.php');

# Connect to the same RabbitMP instance and get a channel
$connection = new AMQPStreamConnection( 'localhost', 5672, 'guest', 'guest' );
$channel    = $connection->channel();

# Make sure the queue "commands" exists
$channel->queue_declare( 'commands' );

# Prepare the Fast CGI Client
$unixDomainSocket = new UnixDomainSocket( 'unix:///var/run/php/php7.1-fpm-commands.sock' );

# Define a callback function that is invoked whenever a message is consumed
$callback = function ( AMQPMessage $message ) use ( $unixDomainSocket )
{
	# Decode the json message and encode it for sending to php-fpm
	$messageArray = json_decode( $message->getBody(), true );
	$body         = http_build_query( $messageArray );

	# Send an async request to php-fpm pool and receive a process ID
	$fpmClient = new Client( $unixDomainSocket );
	
	$request = new PostRequest( '/vagrant/src/worker.php', $body );
	
	$processId = $fpmClient->sendAsyncRequest( $request );

	echo " [x] Spawned process with ID {$processId} for message number {$messageArray['number']}\n";
};

# Request consumption for queue "commands" using the defined callback function
$channel->basic_consume( 'commands', '', false, true, false, false, $callback );

# Wait to finish execution as long as the channel has callbacks
while ( count( $channel->callbacks ) )
{
	$channel->wait();
}
```

**Note:** You should not a use a persistent socket connection to php-fpm here, since you'll receive notices like this once in a while:
`PHP Notice:  fwrite(): send of 399 bytes failed with errno=11 Resource temporarily unavailable ...`. And a persistent connection will also cause the 
php-fpm pool to spawn only one child worker, instead of as much as needed / configured. 

---

## The "Worker"

The worker remains the same for now. It simply logs the received number to a log file and sleeps for one second.

<i class="fa fa-file-o"></i> `src/worker.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

require(__DIR__ . '/../vendor/autoload.php');

error_log( "Processing {$_POST['number']}\n", 3, sys_get_temp_dir() . '/workers.log' );

sleep( 1 );
```

---

## First test

1. (Re)start the "Daemon" and check if it's running properly:
	```bash
	sudo service php-daemon restart && sudo service php-daemon status
	```
2. Watch the process list for spawning `php-fpm pool commands` (in a new terminal tab):
	```bash
	watch -n1 "sudo ps aux | grep 'php-fpm: pool commands' | grep -v grep"
	```
3. Watch the `logs/worker.log` (in a new terminal tab):
	```bash
	tailf "cat /tmp/workers.log"
	```
4. Watch the syslog for spawned process by "Daemon" (in a new terminal tab):
	```bash
	tailf /var/log/syslog | grep '\[x\]'
	```
5. Execute the "Caller" 100 times:
	```bash
	for i in $(seq 1 100); do php7.1 src/caller.php $i; done
	```

**Results:**

* Top-left: Loop sending 100 messages sequentially (via `src/caller.php`)
* Top-right: Current process list showing the spawning and dying children in php-fpm pool "commands"
* Bottom-left: The log file all "Workers" write their received requests to (`src/worker.php`)
* Bottom-right: Syslog showing all async requests to php-fpm (via `src/daemon.php`)

<video width="100%" controls>
  <source src="/assets/video/php/ExperimentalAsyncPHPvol2-1.mp4" type="video/mp4">
Your browser does not support the video tag.
</video>

---

This result is already pretty much what we want, but there are still some "hidden" drawbacks:

1. **The message queue consumption**  
	While the test above runs, a glimpse at the RabbitMQ queue list (`rabbitmqctl list_queues`) shows that there is a queue named "commands" with "0" outstanding messages.
	This is because our messages are not persistent and are immediately delivered to the consumer, that connects first. 
	This is not what we want if we want to scale to multiple "Daemons". Currently there is no "distribution plan" for messages for multiple "Daemons".  
	 
2. **"Daemons" gonna die!**  
	Occasionally consumers of messages happen to die for whatever reason. Since our messages are not persistent yet, they will be deleted from the 
	queue as soon as they were sent to the "Daemon", regardless if they were fully processed or not.

---
	
## Persist and acknowledge

To eliminate the before mentioned drawbacks we should slightly change the usage of RabbitMQ to have work queues (task queues) with persistent messages
instead of volatile messages. So if messages are persistent, we also need to tell the channel when a message (task) was fully processed and can be deleted from the queue. 
Thus we'll send an acknowledgement back to the channel.
   
### The "Caller" version #2

<i class="fa fa-file-o"></i> `src/caller.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

require(__DIR__ . '/../vendor/autoload.php');

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

# Connect and retrieve a channel
$connection = new AMQPStreamConnection( 'localhost', 5672, 'guest', 'guest' );
$channel    = $connection->channel();

# Make sure the queue 'commands' exist
# Make the queue persistent (set 3rd parameter to true)
$channel->queue_declare( 'commands', false, true );

$payload = json_encode( [ 'number' => $argv[1] ], JSON_PRETTY_PRINT );

# Create and send the message
$message = new AMQPMessage(
	$payload,
	[
		# Make message persistent
		'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
	]
);

$channel->basic_publish( $message, '', 'commands' );

echo " [x] Message sent: {$argv[1]}\n";

# Close channel and connection
$channel->close();
$connection->close();
```

**What has changed?**

 *  The "commands" queue was declared to be persistent (durable). This ensures that even if the RabbitMQ server dies the messages won't be lost.  
 	```php
 	$channel->queue_declare( 'commands', false, true ); # Third parameter set to true
 	```

 *  The message was declared to be persistent (durable). This gives us the ability to acknowledge when a message was processed and if it was not fully 
processed it enables RabbitMQ to re-route the message to another consumer, if there is one.  
 	```php
 	$message = new AMQPMessage(
    	$payload,
    	[
    		# Make message persistent
    		'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
    	]
    );
 	```
 
---

<a name="daemon-version-2"></a>
### The "Daemon" version #2

<i class="fa fa-file-o"></i> `src/daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\PostRequest;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require(__DIR__ . '/../vendor/autoload.php');

# Connect to the same RabbitMP instance and get a channel
$connection = new AMQPStreamConnection( 'localhost', 5672, 'guest', 'guest' );
$channel    = $connection->channel();

# Make sure the queue "commands" exists
# Make the queue persistent (set 3rd parameter to true)
$channel->queue_declare( 'commands', false, true );

# Prepare the Fast CGI Client
$unixDomainSocket = new UnixDomainSocket( 'unix:///var/run/php/php7.1-fpm-commands.sock' );

$daemonId = sprintf( 'D-%03d', mt_rand( 1, 100 ) );

# Define a callback function that is invoked whenever a message is consumed
$callback = function ( AMQPMessage $message ) use ( $unixDomainSocket, $daemonId )
{
	# Decode the json message and encode it for sending to php-fpm
	$messageArray             = json_decode( $message->getBody(), true );
	$messageArray['daemonId'] = $daemonId;
	$body                     = http_build_query( $messageArray );

	# Send an async request to php-fpm pool and receive a process ID
	$fpmClient = new Client( $unixDomainSocket );
	
	$request = new PostRequest( '/vagrant/src/worker.php', $body );
	
	$processId = $fpmClient->sendAsyncRequest($request);

	echo " [x] Spawned process with ID {$processId} for message number {$messageArray['number']}\n";

	# Send the ACK(nowledgement) back to the channel for this particular message
	$message->get( 'channel' )->basic_ack( $message->get( 'delivery_tag' ) );
};

# Set the prefetch count to 1 for this consumer
$channel->basic_qos( null, 1, null );

# Request consumption for queue "commands" using the defined callback function
# Enable message acknowledgement (set 4th parameter to false)
$channel->basic_consume( 'commands', '', false, false, false, false, $callback );

# Wait to finish execution as long as the channel has callbacks
while ( count( $channel->callbacks ) )
{
	$channel->wait();
}
```

**What has changed?**

 * Just like in the "Caller", the "commands" queue was declared to be persistent (durable). This ensures that even if the RabbitMQ server dies the messages won't be lost.  
 	```php
 	$channel->queue_declare( 'commands', false, true ); # Third parameter set to true
 	```

 * For the next test, where we will simulate a second "Daemon", a random `$daemonId` was added and set in the request array. So we later can see which "Daemon" processed which messages.  
    ```php
    $daemonId = sprintf( 'D-%03d', mt_rand( 1, 100 ) );
 	# ...
 	$callback = function ( AMQPMessage $message ) use ( $unixDomainSocket, $daemonId )
 	# ...
 	$messageArray['daemonId'] = $daemonId;
    ```

 * We set the [prefetch count](http://www.rabbitmq.com/consumer-prefetch.html) for this consumer to `1`. That means the "Daemon" will only accept one 
message at a time and leave the rest in the queue until the message was processed and acknowledged. So RabbitMQ can distribute the remained messages to another "Daemon", if there is one.
	```php
	$channel->basic_qos( null, 1, null );
	```

 * We enabled message acknowledgement:
	```php
	# 4th parameter set to false
	$channel->basic_consume( 'commands', '', false, false, false, false, $callback );
	```

 * We send an ACK(nowledge) back to the channel as soon as we spawned our "Worker":
	```php
	$message->get( 'channel' )->basic_ack( $message->get( 'delivery_tag' ) );
	```

Make sure to restart the "Daemon" after these changes, otherwise the queue won't be persistent.

---

### The "Worker" version #2

<i class="fa fa-file-o"></i> `src/worker.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

require(__DIR__ . '/../vendor/autoload.php');

error_log(
	" [x] Processing {$_POST['number']} from daemon {$_POST['daemonId']}\n",
	3,
	sys_get_temp_dir() . '/workers.log'
);

sleep( 1 );
```

**What has changed?**

 * The log message was extended with the daemon ID.  
	```php
	" [x] Processing {$_POST['number']} from daemon {$_POST['daemonId']}\n",
	```

---

## Second test

In this test we will...

 * start the previous loop that executes `src/caller.php` 3 times in parallel, just to simulate some traffic.  
	```bash
	for i in 1 2 3; do for j in $(seq 1 100); do php7.1 src/caller.php $j; done & done
	```
	
 * increase the count of max children in php-fpm pool "commands" to 25 (`/etc/php/7.1/fpm/pool.d/commands.conf`):
	```ini
	pm.max_children = 25
	```
	
 * manually start a second "Daemon" to test if messages will be distributed to both running daemons.

**Results:**

* Top-left: Loops sending messages to RabbtMQ (`src/caller.php`)
* Top-middle: Output of our first "Daemon" running via `systemd` 
* Top-right: The second "Daemon" that is started during the test (`src/daemon.php`)
* Bottom-left: The current process list for php-fpm children in pool "commands"
* Bottom-middle: Output of the `/tmp/workers.log` written by all async workers (`src/worker.php`)  
	**NOTE:** After the second daemon was started you can see a second daemon ID in the logs. 
* Bottom-right: Queue list of RabbitMQ incl. count for messages ready and messages unacknowledged  
	```bash
	sudo rabbitmqctl list_queues name messages_ready messages_unacknowledged
	```
	
<video width="100%" controls>
  <source src="/assets/video/php/ExperimentalAsyncPHPvol2-2.mp4" type="video/mp4">
Your browser does not support the video tag.
</video>

---

## Summary

 * We replaced the mostly synchronous Redis PubSub system with the real async message broker RabbitMQ and established a persistent work queue able 
to distribute messages to multiple consumers.

 * We simulated a little scaling by switching a second daemon (consumer) on and off. You can play with variants and settings of the test described above. 
I did and it simply worked in all cases with of course differing performance, but no messages were lost during the tests and that is what matters.

 * This is by far a way better solution than the first try and it seems quite stable. But...  
	Doing the code was a bit messy, because the `php-amqplib` is poorly documented and the object API is not very self-explanatory with a lot of boolean flags. 
	The [official RabbitMQ PHP tutorials](https://www.rabbitmq.com/tutorials/tutorial-one-php.html) helped, but are a little outdated too.

 * In the end, I think it is a slim setup with a lot of potential.
   * 127 lines of PHP code
   * 2 composer dependencies (no subsequent dependencies)
   * 2 config files (for systemd and php-fpm)
   * 1 deb install (rabbitmq-server)
 
The next step will be a real-world implementation and further testing.
 
You can find the example code of this blog post here <i class="fa fa-github"></i> [hollodotme/experimental-async-php-vol2](https://github.com/hollodotme/experimental-async-php-vol2)
 
I hope you liked that post. 
If you're in the mood to give me feedback, [tweet me a tweet](https://twitter.com/hollodotme) 
or [open a discussion on GitHub](https://github.com/hollodotme/experimental-async-php-vol2/issues). 

Thank you.
