## Preamble

Taking the topic of my [previous post](@baseUrl@/php/experimental-async-php-volume-1.html) further, it is time to (hopefully) eliminate some drawbacks
that came along in the first try, such as:

 * Redis has no message backlog. If the "Daemon" is not running for any reason, published messages will never be received.
 * Redis has a [publishing complexity](https://redis.io/commands/publish) of "`O(N+M)` where N is the number of clients subscribed to the receiving channel and M is the total number of subscribed patterns (by any client)." 
    So all "Daemons" (if multiple) would get the same messages and trigger the same "Workers". 

So let's change the goal a bit and bring in a real message broker: [RabbitMQ](https://www.rabbitmq.com).

## Goal

* A PHP script ("Caller") sending messages to the RabbitMQ message queue system
* A PHP script ("Daemon") running as a proper daemon receiving messages from the queue 
* On receiving a message the "Daemon" sends a new async request to a php-fpm socket
* The php-fpm socket serves as an "isolated" pool and spawns child processes
* The children process the requests in background 

---

## Used environment

* OS: Ubuntu Xenial 16.04.1 LTS
* PHP 7.1.0-3+deb.sury.org~xenial+1
* [RabbitMQ Server 3.6.6](https://www.rabbitmq.com/install-debian.html)
* [composer PHP dependency manager](https://getcomposer.org)
* <i class="fa fa-github"></i> [hollodotme/fast-cgi-client](https://github.com/hollodotme/fast-cgi-client)
* <i class="fa fa-github"></i> [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib)

---


