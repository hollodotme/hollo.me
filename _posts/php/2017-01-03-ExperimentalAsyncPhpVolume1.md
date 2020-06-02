---
layout: posts
title: Experimental async PHP vol. 1
tags: [async, PHP, code, OSS, fast-cgi-client]
permalink: /php/experimatal-async-php-volume-1.html
slug: php
---
## Updates

* 2017-03-07: Code updated for [v2.1.0 of hollodotme/fast-cgi-client](https://github.com/hollodotme/fast-cgi-client/tree/v2.1.0).

## Preamble

I recently read a lot about [async PHP](https://medium.com/async-php) and started to do some experiments on my own. 
At the [IPC 2016 (Spring Edition)](http://phpconference.com) in Berlin I attended a talk by [Arne Blankerts](https://twitter.con/arneblankerts) about 
the marriage of PHP and Node.js using the redis pubsub system and web sockets to communicate between server (PHP script), client (JS in Browser) 
and a daemonized application (Node.js app). You can find his [slides here](https://thephp.cc/dates/2016/02/confoo/just-married-node-js-and-php).
  
This is my first experiment to do something similar with PHP only, omitting the web socket/client part.

## Goal

* A PHP script ("Caller") publishing messages to a channel via [Redis PubSub](https://redis.io/topics/pubsub) system
* A PHP script ("Daemon") running as a proper daemon subscribing to a redis channel 
* On receiving a message the "Daemon" sends a new async request to a php-fpm socket
* The php-fpm socket serves as an "isolated" pool and spawns child processes
* The children process the requests in background 

<span class="img center">
[![Caller->Redis->Daemon->Socket->Worker]({{ '/assets/img/posts/caller-redis-daemon-socket-worker.png' | relative_url }})]({{ '/assets/img/posts/caller-redis-daemon-socket-worker.png' | relative_url }})
</span>

---

## Used environment

* OS: Ubuntu Xenial 16.04.1 LTS
* PHP 7.1.0-3+deb.sury.org~xenial+1 with <i class="fa fa-github"></i> [phpredis 3.0.0](https://github.com/phpredis/phpredis/tree/3.0.0)
* [Redis Server 3.2.6](https://redis.io/download)
* [composer PHP dependency manager](https://getcomposer.org)
* <i class="fa fa-github"></i> [hollodotme/fast-cgi-client](https://github.com/hollodotme/fast-cgi-client)

---

## The "Caller"

<i class="fa fa-file-o"></i> `src/caller.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

require(__DIR__ . '/../vendor/autoload.php');

$redis = new \Redis();
$redis->connect( 'localhost', 6379, 2.0 );

$message = [
	'timestamp' => date( 'c' ),
];

$redis->publish( 'commands', json_encode( $message, JSON_PRETTY_PRINT ) );
```

This script creates a redis client and publishes a message containing the current timestamp to the channel "commands".

---

## The "Daemon" Version #1

<i class="fa fa-file-o"></i> `src/daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

require(__DIR__ . '/../vendor/autoload.php');

$redisHost = '127.0.0.1';
$redisPort = 6379;

$redis     = new \Redis();
$connected = $redis->connect( $redisHost, $redisPort );

if ( $connected )
{
	echo "Connected to redis on {$redisHost}:{$redisPort}\n";

	$redis->subscribe(
		[ 'commands' ],
		function ( \Redis $redis, string $channel, string $message )
		{
			echo "Channel: $channel\n";
			echo "Payload: $message\n";
		}
	);
}
else
{
	echo "Could not connect to redis.\n";
}
```

This script creates a redis client and subscribes to the channel "commands" if the connection was successful. 
If a message was published to the "commands" channel the defined callback function will receive that message alongside with the name of the channel
and the current redis client instance. As you can see the script simply prints the channel name and the message payload to stdout. 
We will change this later, but for checking the basic setup this is sufficient.

You may wonder why there is nothing like a `while (true)` loop in this script that makes it run infinitely. This is because the `$redis->subscribe()` 
statement already contains such a loop behaviour since it opens a socket to the redis server and listens for incoming messages.
  
To have an infinite listening on the channel it is important not to set a timeout when connecting to redis (3rd parameter in `$redis->connect()`) and to 
disable the `php.ini`'s `default_socket_timeout` with the value `-1`. The next paragraph will describe how we can achieve that without disabling it 
globally in the `php.ini`.   

---

<a name="daemonize-the-daemon"></a>
## Daemonize the "Daemon" 

The following `systemd` service script will let our "Daemon" run as a linux service with start/stop function and auto-restart.

<i class="fa fa-file-o"></i> `/etc/systemd/system/php-daemon.service`
```
[Unit]
Description=PHP Daemon

[Service]
Type=simple
ExecStart=/usr/bin/php7.1 -d "default_socket_timeout=-1" -f /fullpath/to/daemon.php
Restart=always

[Install]
WantedBy=multi-user.target
```

Enable the service with: 

 * `sudo systemctl enable php-daemon.service`
 
**Note:** If you change the php-daemon.service config after you enabled it, you need to run `sudo systemctl daemon-reload`. 

Now you can start/stop the "Daemon" with:
 
 * `sudo service php-daemon start`
 * `sudo service php-daemon stop`
 * `sudo service php-daemon restart`

... and see its current status with latest output:

 * `sudo service php-daemon status`

As you can see there is an option `-d "default_socket_timeout=-1"` in the command to execute (line 6). This option overwrites the earlier mentioned 
`php.ini` setting `default_socket_timeout` with the value `-1` only for this particular process. So we completely disabled the default socket timeout.

The `Restart=always` directive will let our "Daemon" restart automatically if it crashes or its process was manually killed.

---

## First test

```bash
# Start the daemon
$ sudo service php-daemon start

# Check if daemon is running properly 
$ sudo service php-daemon status
● php-daemon.service - PHP Daemon
   
   ...

Jan 01 23:53:09 www systemd[1]: Started PHP Daemon.
Jan 01 23:53:09 www php7.1[4081]: Connected to redis on 127.0.0.1:6379

# Send a message
$ /usr/bin/php7.1 src/caller.php

# Check if message was received
$ sudo service php-daemon status
● php-daemon.service - PHP Daemon
   
   ...

Jan 01 23:53:09 www systemd[1]: Started PHP Daemon.
Jan 01 23:53:09 www php7.1[4081]: Connected to redis on 127.0.0.1:6379
Jan 02 00:33:28 www php7.1[4081]: Channel: commands
Jan 02 00:33:28 www php7.1[4081]: Payload: {
Jan 02 00:33:28 www php7.1[4081]:     "timestamp": "2017-01-02T00:33:28+01:00"
Jan 02 00:33:28 www php7.1[4081]: }

# Looks good! :)
```

---

## The "Worker"

Now we want to spawn a worker and hand over the message from the "Caller" to it. For starters the "Worker" will simply log the received timestamp from 
the message to a log file and than sleep 1 second before it dies.

<i class="fa fa-file-o"></i> `src/worker.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

require(__DIR__ . '/../vendor/autoload.php');

error_log( $_POST['timestamp'] . "\n", 3, __DIR__ . '/../logs/workers.log' );

sleep( 1 );
```

---

## The "Daemon" version #2

To hand over the message to the worker, we will let the "Daemon" send a request to the PHP-FPM socket using 
<i class="fa fa-github"></i> [hollodotme/fast-cgi-client](https://github.com/hollodotme/fast-cgi-client).

<i class="fa fa-file-o"></i> `src/daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\PostRequest;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;

require(__DIR__ . '/../vendor/autoload.php');

$redisHost = '127.0.0.1';
$redisPort = 6379;

$redis     = new \Redis();
$connected = $redis->connect( $redisHost, $redisPort );

if ( $connected )
{
	echo "Connected to redis on {$redisHost}:{$redisPort}\n";

	$redis->subscribe(
		[ 'commands' ],
		function ( \Redis $redis, string $channel, string $message )
		{
			$messageArray = json_decode( $message, true );
			$body         = http_build_query( $messageArray );

			$connection = new UnixDomainSocket( 'unix:///var/run/php/php7.1-fpm.sock' );
			$fpmClient  = new Client( $connection );
			
			$request    = new PostRequest( '/fullpath/to/worker.php', $body );
			
			$processId  = $fpmClient->sendAsyncRequest( $request );

			echo "Spawned process with ID: {$processId}\n";
		}
	);
}
else
{
	echo "Could not connect to redis.\n";
}
```

---

## Second test

```bash
# Restart the daemon
$ sudo service php-daemon restart

# Check if daemon is running properly 
$ sudo service php-daemon status
● php-daemon.service - PHP Daemon
   
   ...

Jan 03 20:49:16 www systemd[1]: Started PHP Daemon.
Jan 03 20:49:16 www php7.1[4478]: Connected to redis on 127.0.0.1:6379

# Send 10 messages
$ for i in 1 2 3 4 5 6 7 8 9 10; do /usr/bin/php7.1 src/caller.php; done

# Check if message was received
$ sudo service php-daemon status
● php-daemon.service - PHP Daemon
   
   ...

Jan 03 20:56:06 www php7.1[4525]: Spawned process with ID: 9440
Jan 03 20:56:06 www php7.1[4525]: Spawned process with ID: 42058
Jan 03 20:56:06 www php7.1[4525]: Spawned process with ID: 43385
Jan 03 20:56:06 www php7.1[4525]: Spawned process with ID: 58521
Jan 03 20:56:07 www php7.1[4525]: Spawned process with ID: 60557
Jan 03 20:56:07 www php7.1[4525]: Spawned process with ID: 16706
Jan 03 20:56:07 www php7.1[4525]: Spawned process with ID: 10623
Jan 03 20:56:07 www php7.1[4525]: Spawned process with ID: 3811
Jan 03 20:56:07 www php7.1[4525]: Spawned process with ID: 29023
Jan 03 20:56:07 www php7.1[4525]: Spawned process with ID: 61505

# Check the log file
$ cat /path/to/workers.log
2017-01-03T20:56:06+01:00
2017-01-03T20:56:06+01:00
2017-01-03T20:56:06+01:00
2017-01-03T20:56:06+01:00
2017-01-03T20:56:07+01:00
2017-01-03T20:56:07+01:00
2017-01-03T20:56:07+01:00
2017-01-03T20:56:07+01:00
2017-01-03T20:56:07+01:00
2017-01-03T20:56:07+01:00

# Looks good again.
```

---

## Setup a separate php-fpm pool

Until now all the async requests we have sent to php-fpm were processed by the default `www` pool. 
So we are using the same pool for all the web requests and our async requests. This is not very elegant, since we could harm the performance of our 
web requests this way.

The solution is simple: Let's set up a separate php-fpm pool with an own socket that will execute our async requests.

### Pool config

Create a new pool config file:

<i class="fa fa-file-o"></i> `/etc/php/7.1/fpm/pool.d/commands.conf`

```ini
; Pool name
[commands]

; Process ownership
user = www-data
group = www-data

; Socket path
listen = /var/run/php/php7.1-fpm-commands.sock

; Socket ownership
listen.owner = www-data
listen.group = www-data

; Process management
; Choosing 'ondemand' to create children only if new processes are requested (less overhead)
pm = ondemand

; Maximum of children that can be alive at the same time
pm.max_children = 5

; Number of seconds after which an idle children will be killed
pm.process_idle_timeout = 10s

; Access log file
access.log = /var/log/php/php7.1-fpm-commands.access.log
```

Before restarting the php-fpm service make sure the access log file exists:

```bash
$ sudo mkdir -p /var/log/php
$ sudo touch /var/log/php/php7.1-fpm-commands.access.log
```

Now restart the php-fpm service:

```bash
$ sudo service php7.1-fpm restart
```

---

## The "Daemon" version #3

Now let's change our "Daemon" to use the newly created socket for all the async requests.

<i class="fa fa-file-o"></i> `src/daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\PostRequest;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;

require(__DIR__ . '/../vendor/autoload.php');

$redisHost = '127.0.0.1';
$redisPort = 6379;

$redis     = new \Redis();
$connected = $redis->connect( $redisHost, $redisPort );

if ( $connected )
{
	echo "Connected to redis on {$redisHost}:{$redisPort}\n";

	$redis->subscribe(
		[ 'commands' ],
		function ( \Redis $redis, string $channel, string $message )
		{
			$messageArray = json_decode( $message, true );
			$body         = http_build_query( $messageArray );

			# Use new socket at /var/run/php/php7.1-fpm-commands.sock now!
			$connection = new UnixDomainSocket( 'unix:///var/run/php/php7.1-fpm-commands.sock' );
			$fpmClient  = new Client( $connection );
			
			$request    = new PostRequest( '/fullpath/to/worker.php', $body );
			
			$processId  = $fpmClient->sendAsyncRequest( $request );

			echo "Spawned process with ID: {$processId}\n";
		}
	);
}
else
{
	echo "Could not connect to redis.\n";
}
```

---

## Third test

```bash
# Restart the daemon
$ sudo service php-daemon restart

# Check if daemon is running properly 
$ sudo service php-daemon status
● php-daemon.service - PHP Daemon
   
   ...

Jan 03 21:30:58 www systemd[1]: Started PHP Daemon.
Jan 03 21:30:58 www php7.1[4873]: Connected to redis on 127.0.0.1:6379

# Send 10 messages
$ for i in 1 2 3 4 5 6 7 8 9 10; do /usr/bin/php7.1 src/caller.php; done

# Check if message was received
$ sudo service php-daemon status
● php-daemon.service - PHP Daemon
   
   ...

Jan 03 21:31:35 www php7.1[4873]: Spawned process with ID: 22515
Jan 03 21:31:35 www php7.1[4873]: Spawned process with ID: 4418
Jan 03 21:31:35 www php7.1[4873]: Spawned process with ID: 9866
Jan 03 21:31:35 www php7.1[4873]: Spawned process with ID: 24047
Jan 03 21:31:35 www php7.1[4873]: Spawned process with ID: 54871
Jan 03 21:31:36 www php7.1[4873]: Spawned process with ID: 58282
Jan 03 21:31:36 www php7.1[4873]: Spawned process with ID: 21316
Jan 03 21:31:36 www php7.1[4873]: Spawned process with ID: 4216
Jan 03 21:31:36 www php7.1[4873]: Spawned process with ID: 50098
Jan 03 21:31:36 www php7.1[4873]: Spawned process with ID: 16051

# Check the log file
$ cat /path/to/workers.log
2017-01-03T21:31:35+01:00
2017-01-03T21:31:35+01:00
2017-01-03T21:31:35+01:00
2017-01-03T21:31:35+01:00
2017-01-03T21:31:35+01:00
2017-01-03T21:31:36+01:00
2017-01-03T21:31:36+01:00
2017-01-03T21:31:36+01:00
2017-01-03T21:31:36+01:00
2017-01-03T21:31:36+01:00

# Check access log file of the new pool
$ cat /var/log/php/php7.1-fpm-commands.access.log
- -  03/Jan/2017:21:31:35 +0100 "POST " 200
- -  03/Jan/2017:21:31:35 +0100 "POST " 200
- -  03/Jan/2017:21:31:35 +0100 "POST " 200
- -  03/Jan/2017:21:31:35 +0100 "POST " 200
- -  03/Jan/2017:21:31:35 +0100 "POST " 200
- -  03/Jan/2017:21:31:55 +0100 "POST " 200
- -  03/Jan/2017:21:31:55 +0100 "POST " 200
- -  03/Jan/2017:21:31:55 +0100 "POST " 200
- -  03/Jan/2017:21:31:55 +0100 "POST " 200
- -  03/Jan/2017:21:31:55 +0100 "POST " 200

# Looks good again.
```

When you check the process list in a parallel tab you'll see that 5 children (`php-fpm: pool commands`) will come to life and die again 10 seconds after they became idle, 
just as configured.
 
```bash
$ watch -n1 "ps aux | grep php-fpm"
root      4788  0.0  1.1 476644 46016 ?        Ss   21:19   0:00 php-fpm: master process (/etc/php/7.1/fpm/php-fpm.conf)
www-data  4790  0.0  0.2 476636 11768 ?        S    21:19   0:00 php-fpm: pool www
www-data  4791  0.0  0.2 476636 11768 ?        S    21:19   0:00 php-fpm: pool www
vagrant   4944  0.1  0.0  14916  3324 pts/1    S+   21:34   0:00 watch -n1 ps aux | grep php-fpm
www-data  4998  0.0  0.3 477108 15588 ?        S    21:34   0:00 php-fpm: pool commands
www-data  5000  0.0  0.3 477108 15588 ?        S    21:34   0:00 php-fpm: pool commands
www-data  5002  0.0  0.3 477108 15588 ?        S    21:34   0:00 php-fpm: pool commands
www-data  5004  0.0  0.3 477108 15588 ?        S    21:34   0:00 php-fpm: pool commands
www-data  5006  0.0  0.3 477108 15588 ?        S    21:34   0:00 php-fpm: pool commands
vagrant   5081  0.0  0.0  14916   972 pts/1    S+   21:34   0:00 watch -n1 ps aux | grep php-fpm
vagrant   5082  0.0  0.0   4508   848 pts/1    S+   21:34   0:00 sh -c ps aux | grep php-fpm
vagrant   5084  0.0  0.0  14524  1048 pts/1    S+   21:34   0:00 grep php-fpm
```

---

## Summary

 * We established a basic system to start PHP tasks asynchronously based on published simple messages.
 	The messages used here were of course oversimplified and would contain meaningful payload in real world applications to trigger real commands.

 * We used the redis pub/sub system to decouple our application ("Caller") from the background processing system ("Daemon" & "Workers").
   	Note that "Daemon" + "Workers" could run on a completely separate system, as long as "Daemon" is connected to the same redis instance. 
   	You could even run multiple daemons on multiple systems, each processing individual redis-channels.
   	Redis can surely be replaced by another pub/sub system or a message queue. I chose redis, because of its simplicity.

 * We isolated the workload of the background processing ("Workers") by setting up a separate php-fpm pool.
 	That pool could now be fine-tuned to fit the needs of your background processes, without effecting other applications using php-fpm.

You can find the example code of this blog post here <i class="fa fa-github"></i> [hollodotme/experimental-async-php-vol1](https://github.com/hollodotme/experimental-async-php-vol1)

Well, you may noticed there are some drawbacks with scaling when using Redis. I'll try to eliminate them in my next post:
**[Experimental async PHP volume 2](/php/experimental-async-php-volume-2.html)**

I hope you liked that post. 
If you're in the mood to give me feedback, [tweet me a tweet](https://twitter.com/hollodotme) 
or [open a discussion on GitHub](https://github.com/hollodotme/experimental-async-php-vol1/issues). 

Thank you.
