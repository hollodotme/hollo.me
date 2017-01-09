## Preamble

Taking the topic of my [previous post](@baseUrl@/php/experimental-async-php-volume-1.html) further, it is time to (hopefully) eliminate some drawbacks
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

---

## Used environment

* OS: Ubuntu Xenial 16.04.1 LTS
* PHP 7.1.0-3+deb.sury.org~xenial+1
* [RabbitMQ Server 3.6.6](https://www.rabbitmq.com/install-debian.html)
* [composer PHP dependency manager](https://getcomposer.org)
* <i class="fa fa-github"></i> [hollodotme/fast-cgi-client](https://github.com/hollodotme/fast-cgi-client)
* <i class="fa fa-github"></i> [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib)

---


## The "Caller"

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

## The "Daemon"

Also in the "Daemon" we replace the Redis subscription with a basic consumption of messages sent to the "commands" queue of the "Broker".

When a message is consumed a callback function (Closure) will be invoked. 
This Closure will again send a request to our previously set up php-fpm pool, thus to our "Workers".

<i class="fa fa-file-o"></i> `src/daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

use hollodotme\FastCGI\Client;
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
	$processId = $fpmClient->sendAsyncRequest(
		[
			'GATEWAY_INTERFACE' => 'FastCGI/1.0',
			'REQUEST_METHOD'    => 'POST',
			'SCRIPT_FILENAME'   => '/vagrant/src/worker.php',
			'SERVER_SOFTWARE'   => 'php/fcgiclient',
			'REMOTE_ADDR'       => '127.0.0.1',
			'REMOTE_PORT'       => '9985',
			'SERVER_ADDR'       => '127.0.0.1',
			'SERVER_PORT'       => '80',
			'SERVER_NAME'       => 'myServer',
			'SERVER_PROTOCOL'   => 'HTTP/1.1',
			'CONTENT_TYPE'      => 'application/x-www-form-urlencoded',
			'CONTENT_LENGTH'    => mb_strlen( $body ),
		],
		$body
	);

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
php-fpm pool to spawn only one child worker, instead of 5. 

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

<video width="100%" controls>
  <source src="@baseUrl@/video/php/ExperimentalAsyncPHPvol2-1.mp4" type="video/mp4">
Your browser does not support the video tag.
</video>
