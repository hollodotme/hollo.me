## Preamble

I recently read a lot about [async PHP](https://medium.com/async-php) and started to do some experiments on my own. 
At the [IPC 2016 (Spring Edition)](http://phpconference.com) in Berlin I attended a talk by [Arne Blankerts](https://twitter.con/arneblankerts) about 
the marriage of PHP and Node.js using the redis pubsub system and web sockets to communicate between server (PHP script), client (JS in Browser) 
and a daemonized application (Node.js app). You can find his [slides here](https://thephp.cc/dates/2016/02/confoo/just-married-node-js-and-php).
  
This is my first experiment to do something similar with PHP only.

## Goal

* A PHP script ("Caller") publishing messages via [Redis PubSub](https://redis.io/topics/pubsub) system
* A PHP script ("Daemon") running as a proper daemon on a unix system with an event loop
* "Daemon" is listening to channels of the Redis PubSub system
* On receiving a message the "Daemon" starts a new request to the php-fpm socket ("Worker")

## Used environment

* OS: Ubuntu Xenial 16.04.1 LTS
* PHP 7.1.0-3+deb.sury.org~xenial+1 with <i class="fa fa-github"></i> [phpredis 3.0.0](https://github.com/phpredis/phpredis/tree/3.0.0)
* [Redis Server 3.2.6](https://redis.io/download)
* [composer PHP dependency manager](https://getcomposer.org)
* <i class="fa fa-github"></i> [adoy/PHP-FastCGI-Client](https://github.com/adoy/PHP-FastCGI-Client)

---

## The "Caller"

<i class="fa fa-file-o"></i> `caller.php`

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

<i class="fa fa-file-o"></i> `daemon.php`

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

## Daemonize the "Daemon" 

The following `systemd` service script will let our "Daemon" run as a linux service with start/stop function and auto-respawn.

<i class="fa fa-file-o"></i> `/etc/systemd/system/php-daemon.service`
```
[Unit]
Description=PHP Daemon

[Service]
Type=simple
Restart=always
ExecStart=/usr/bin/php7.1 -d "default_socket_timeout=-1" -f /path/to/daemon.php

[Install]
WantedBy=multi-user.target
```

Enable the service with: 

 * `sudo systemctl enable php-daemon.service`

Now you can start/stop the "Daemon" with:
 
 * `sudo service php-daemon start`
 * `sudo service php-daemon stop`
 * `sudo service php-daemon restart`

... and see its current status and latest output with:

 * `sudo service php-daemon status`

As you can see there is an option `-d "default_socket_timeout=-1"` in the command to execute (line 7). This option overwrites the earlier mentioned 
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
   Loaded: loaded (/etc/systemd/system/php-daemon.service; enabled; vendor preset: enabled)
   Active: active (running) since Sun 2017-01-01 23:53:09 CET; 3s ago
 Main PID: 4081 (php7.1)
    Tasks: 1
   Memory: 9.7M
      CPU: 39ms
   CGroup: /system.slice/php-daemon.service
           └─4081 /usr/bin/php7.1 -d default_socket_timeout=-1 -f /vagrant/src/daemon.php

Jan 01 23:53:09 www systemd[1]: Started PHP Daemon.
Jan 01 23:53:09 www php7.1[4081]: Connected to redis on 127.0.0.1:6379

# Send a message
$ /usr/bin/php7.1 /path/to/caller.php

# Check if message was received
$ sudo service php-daemon status
● php-daemon.service - PHP Daemon
   Loaded: loaded (/etc/systemd/system/php-daemon.service; enabled; vendor preset: enabled)
   Active: active (running) since Sun 2017-01-01 23:53:09 CET; 40min ago
 Main PID: 4081 (php7.1)
    Tasks: 1
   Memory: 9.7M
      CPU: 39ms
   CGroup: /system.slice/php-daemon.service
           └─4081 /usr/bin/php7.1 -d default_socket_timeout=-1 -f /vagrant/src/daemon.php

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

<i class="fa fa-file-o"></i> `worker.php`

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
<i class="fa fa-github"></i> [adoy/PHP-FastCGI-Client](https://github.com/adoy/PHP-FastCGI-Client).

<i class="fa fa-file-o"></i> `daemon.php`

```php
<?php declare(strict_types = 1);

namespace hollodotme\AsyncPhp;

use Adoy\FastCGI\Client;

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
			$messageArray = json_decode( $message );
			$body         = http_build_query( $messageArray );

			$fpmClient = new Client( 'unix:///var/run/php/php7.1-fpm.sock', -1 );
			$processId = $fpmClient->async_request(
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

			echo "Spawned process with ID: {$processId}\n";
		}
	);
}
else
{
	echo "Could not connect to redis.\n";
}
```
