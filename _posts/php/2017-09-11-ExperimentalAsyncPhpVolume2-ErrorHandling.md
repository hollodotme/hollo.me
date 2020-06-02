---
layout: posts
title: Experimental async PHP vol. 2
subtitle: Q&A - Error handling and retry
tags: [qa, error handling, retry, async, PHP, code]
permalink: /php/experimental-async-php-volume-2-error-handling.html
slug: php
---

## Question

Today <i class="fa fa-twitter"></i> [Youri Thielen](https://twitter.com/yourithielen) came up with the following question regarding my post on [Experimental async PHP - VOL. 2](/php/experimental-async-php-volume-2.html):

<blockquote class="twitter-tweet" data-partner="tweetdeck"><p lang="en" dir="ltr"><a href="https://twitter.com/hollodotme">@hollodotme</a> Hi, quick question on <a href="https://t.co/GAPnipHclr">https://t.co/GAPnipHclr</a>. How do you handle error cases in the worker and retries?</p>&mdash; Youri Thielen (@yourithielen) <a href="https://twitter.com/yourithielen/status/907266443259121665">September 11, 2017</a></blockquote>
<script async src="//platform.twitter.com/widgets.js" charset="utf-8"></script>

---

## Answers

Please be aware that the following are only thoughts about possibly valid answers to Youri's question. None of this is practically approved or implemented in any way.


### Retry

The first thing I would do is trying to benefit from the redistribution feature of the message broker (RabbitMQ). 
Therefor I would change the code of the [Daemon version 2](/php/experimental-async-php-volume-2.html#daemon-version-2) and add a response callback which was introduced in [hollodotme/fast-cgi-client v2.2.0](https://github.com/hollodotme/fast-cgi-client/blob/master/CHANGELOG.md#220---2017-04-15).
This callback can be used to evaluate the worker's response and let you decide either you want to acknowledge (_ack_) the message on success or [negative-acknowledge](https://www.rabbitmq.com/nack.html) (_nack_) it on error for redistribution.
In case of a **_nack_** the broker will send the message (preferably) to another daemon.

In order to do so it would be a good idea to define responses for the worker. To keep it simple, I assume the worker can have only one of these 3 responses:

* SUCCEEDED (worker successfully executed)
* REJECTED (worker cannot execute at the moment, try again)
* FAILED (worker failed to execute, don't try again)

The change could look like this:

<i class="fa fa-file-o"></i> `src/daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\PostRequest;
use hollodotme\FastCGI\Interfaces\ProvidesResponseData;
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

$daemonId = sprintf( 'D-%03d', random_int( 1, 100 ) );

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
	
	$request->addResponseCallbacks(
    	function( ProvidesResponseData $response ) use ( $message )
    	{
    		# In both cases (SUCCEEDED & FAILED) we want the message to be removed from the queue
    		if ( in_array($response->getBody(), ['SUCCEEDED', 'FAILED'], true) )
			{
				# Send the ACK(nowledgement) back to the channel for this particular message
				$message->get( 'channel' )->basic_ack( $message->get( 'delivery_tag' ) );
				
				return;
			}
			
			# In case of REJECTED we want the message to be redistributed
			
			/**
             * Send a N(egative)ACK(nowledgement) back to the channel for this particular message
			 * @see https://github.com/php-amqplib/php-amqplib/blob/master/demo/basic_nack.php 
			 */
            $message->get( 'channel' )->basic_nack( $message->get( 'delivery_tag' ) );
    	}
    );
	
	$processId = $fpmClient->sendAsyncRequest($request);

	echo " [x] Spawned process with ID {$processId} for message number {$messageArray['number']}\n";

	$fpmClient->waitForResponse($processId);
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

This is fine so far, but now we have a problem. The call of `$fpmClient->waitForResponse($processId)` will cause the consume callback to be blocking 
until the worker responded. That would be equal to putting the worker's code directly into the consumer callback. So the benefit of parallelised workers would be gone.  

Fortunately the fast-cgi-client supports loop integration and we can get rid of this problem, if we slightly change the code again:

<i class="fa fa-file-o"></i> `src/daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\PostRequest;
use hollodotme\FastCGI\Interfaces\ProvidesResponseData;
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

$daemonId = sprintf( 'D-%03d', random_int( 1, 100 ) );

# Use only one instance for all requests
$fpmClient = new Client( $unixDomainSocket );

# Define a callback function that is invoked whenever a message is consumed
$callback = function ( AMQPMessage $message ) use ( $fpmClient, $daemonId )
{
	# Decode the json message and encode it for sending to php-fpm
	$messageArray             = json_decode( $message->getBody(), true );
	$messageArray['daemonId'] = $daemonId;
	$body                     = http_build_query( $messageArray );

	# Send an async request to php-fpm pool and receive a process ID
	$request = new PostRequest( '/vagrant/src/worker.php', $body );
	
	$request->addResponseCallbacks(
    	function( ProvidesResponseData $response ) use ( $message )
    	{
    		# In both cases (SUCCEEDED & FAILED) we want the message to be removed from the queue
    		if ( in_array($response->getBody(), ['SUCCEEDED', 'FAILED'], true) )
			{
				# Send the ACK(nowledgement) back to the channel for this particular message
				$message->get( 'channel' )->basic_ack( $message->get( 'delivery_tag' ) );
				
				return;
			}
			
			# In case of REJECTED we want the message to be redistributed
			
			/**
             * Send a N(egative)ACK(nowledgement) back to the channel for this particular message
			 * @see https://github.com/php-amqplib/php-amqplib/blob/master/demo/basic_nack.php 
			 */
            $message->get( 'channel' )->basic_nack( $message->get( 'delivery_tag' ) );
    	}
    );
	
	$processId = $fpmClient->sendAsyncRequest($request);

	echo " [x] Spawned process with ID {$processId} for message number {$messageArray['number']}\n";
};

# Set the prefetch count to 5 for this consumer
$channel->basic_qos( null, 5, null );

# Request consumption for queue "commands" using the defined callback function
# Enable message acknowledgement (set 4th parameter to false)
$channel->basic_consume( 'commands', '', false, false, false, false, $callback );

# Wait to finish execution as long as the channel has callbacks
while ( count( $channel->callbacks ) )
{
	# Notify all callbacks of requests which were already responded by its workers
	$fpmClient->handleReadyResponses();
	
	$channel->wait();
}
```

Four things have changed now:

1. The instance `$fpmClient` is now created in the global scope of the daemon script above the declaration of `$callback` and then `use`d by the consumer callback.
2. The blocking `$fpmClient->waitForResponse($processId)` was removed from consumer callback.
3. The reactive method `$fpmClient->handleReadyResponses()` was added in the main (endless) loop. This method calls the response callback of each request as soon as the worker responded and is only blocking for the time of executing the response callback. 
   For more information, see the [documentation](https://github.com/hollodotme/fast-cgi-client#sending-multiple-requests-and-notifying-callbacks-reactive).
4. The consume count of the daemon was raised to `5`, so we have at most 5 parallel running workers.

Now we can remove or redistribute (retry) messages based on the worker's result, in a sort order based on worker's execution time with a non-blocking consumer.

**I call that a first achievement. :D**

---

### Error handling

General speaking, I am not a fan of complex self-healing systems whet it comes to error handling. My personal approach is: 

1. trying to reduce the probability of errors by writing solid and tested code in the first place,
2. using a sane error reporting tool like e.g. [Sentry](https://getsentry.com) with sane and precise log level setup,
3. creating an environment that allows immediate troubleshooting e.g. by getting slack-notifications on critical incidents (only) and
4. always asking myself about the possible frequency and consequences of a particular error. 
   In most cases it's not as bad as you may think it is; and the effort of establishing a self-healing mechanism doesn't pay off very often. 

Lets get back to the topic: 
  
**Regarding the daemon** error handling could be quite simple. I would install my favourite error handler (like Sentry) that reports errors to a log central which maybe notifies me directly.
Since the daemon is registered as a system service ([see here](/php/experimental-async-php-volume-1.html#daemonize-the-daemon)), I don't have to care
about restarting it in case of a crash. Additionally RabbitMQ takes also note of a crashed daemon/consumer and will automatically redistribute all un-acknowledged messages 
to other daemons or the same as soon as it has restarted. Basically there are no other important incidents that need to be covered here, once the code is tested. 
Everything else would simply be reported/logged.

**Regarding the worker** error handling should be isolated and thus separated from the daemon and message queue. 
I think of a worker as a microservice (sorry for the buzzword). 
It should be self-contained, replaceable and provide a reliable API to the outside world. 
In this case the API is quite simple, because it consists of a defined request payload [IN] and the 3 aforementioned responses 
(**SUCCEEDED**, **REJECTED** and **FAILED**) [OUT]. 

So we should try our best to make sure that this API won't break. Again I would install my favourite error handler to get errors reported/logged.
The API could be guarded by a simple `try {} catch() {}` block for `\Throwable` in the worker script like this:

```php
<?php declare(strict_types=1);

namespace YourVendor\YourProject;

$errorHandler = new MyFavouriteErrorHandler(); 
$errorHandler->install(); 

try 
{
	$result = (new MyWorker())->doStuff($_POST);
	
	echo $result->succeeded() ? 'SUCCEEDED' : 'REJECTED';
}
catch (\Throwable $e)
{
	$errorHandler->captureException($e);
	
	echo 'FAILED';
}
```

I would also recommend to keep the workers as small and simple as possible and well tested of course.
The more complex the API gets, the harder it is to guard.

But I repeat, all these are my options and the presumably "right approach" always depends on the actual use-case.
Even though, I hope these thoughts are helpful. 
   