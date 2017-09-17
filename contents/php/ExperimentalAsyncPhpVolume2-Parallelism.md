## Question

Today <i class="fa fa-twitter"></i> [Youri Thielen](https://twitter.com/yourithielen) came up with the following question regarding my post on [Experimental async PHP - VOL. 2](@baseUrl@/php/experimental-async-php-volume-2.html):

> Awesome, looks pretty workable, will probably run some experiments with this! Just out of interest, how do you achieve the parallel
> 2/2 streams? I see stream_socket_client but no stream_set_blocking. I see you use PHPs yield, that's how you achieve 'parallelism' right?

---

## Answers

[See my original answer in this twitter thread.](https://twitter.com/hollodotme/status/909484318522822663)

### Using yield

In this case `yield` is used as an iterator and has therefor nothing to to with parallelism.  
I recommend reading [this blog post](http://blog.kelunik.com/2017/09/14/an-introduction-to-generators-in-php.html) 
by <i class="fa fa-twitter"></i> [Niklas Keller](https://twitter.com/kelunik) which explains the different use-cases of
generators and yield in PHP.

### Understanding PHP-FPM

To understand why the workers are executed in parallel, you first need to understand how PHP-FPM works.

PHP-FPM organizes it's processes in so-called pools, which can be easily configured and added. [See example below.](#adding-php-fpm-pools) 
Each pool is represented by a master process that is listening on a particular socket (network or unix domain socket). 
This master process queues incoming request, spawns child processes in background, distributes the workload to its child processes 
and writes the responses back to the socket/stream as soon as the child processes finished and handed over their results. 

A pool defines how child-processes are spawned, how much of them can be alive at the same time, and how/when they die.

PHP-FPM uses the [FastCGI protocol](http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html) for the socket communication.

If you are using PHP-FPM for your web application this process list should look familiar to you.

```bash
root      Ss   Sep16   0:05 php-fpm: master process (/etc/php/7.1/fpm/php-fpm.conf)
www-data  S    14:01   0:00 php-fpm: pool www
www-data  S    14:01   0:00 php-fpm: pool www
``` 

You can see that there is one master process for the pool named "www" and two child processes ready to handle requests.
This is more or less the default that is shipped with php (depends on your distribution).

#### PHP-FPM pools explained

A PHP-FPM pool can be added by placing a simple config file to `/etc/php/7.1/fpm/pool.d/<POOL-NAME>.conf`.  
(This path may differ depending on your distribution.)

The config for the pool seen above looks like this:

<i class="fa fa-file-o"></i> `www.conf`

```ini
; Name of the pool
[www]

; User and group under which the child processes will run
user = www-data
group = www-data

; Socket the master process is listening to
; Could also be a network socket like 127.0.0.1:9000
listen = /run/php/php7.1-fpm.sock

; Permissions for user/group that can access the socket (only for unix domain socket relevant)
listen.owner = www-data
listen.group = www-data

; Set listen(2) backlog.
; Default Value: 511 (-1 on FreeBSD and OpenBSD)
listen.backlog = 511

; Process management type (more explanation below)
pm = dynamic

; Maximum amount of parallel child processes
pm.max_children = 5

; Amount of child processes that are always alive, even if there is nothing to do
; That's why you see 2 child processes in the process list above
; Note: Used only when pm is set to 'dynamic'
; Default Value: min_spare_servers + (max_spare_servers - min_spare_servers) / 2 
pm.start_servers = 2

; The desired minimum number of idle server processes.
; Note: Used only when pm is set to 'dynamic'
; Note: Mandatory when pm is set to 'dynamic'
pm.min_spare_servers = 1

; The desired maximum number of idle server processes.
; Note: Used only when pm is set to 'dynamic'
; Note: Mandatory when pm is set to 'dynamic'
pm.max_spare_servers = 3
```

**Please note** the `listen.backlog` setting, which defines how much requests PHP-FPM will accept until it rejects a request.
This setting implies that PHP-FPM is a request queue.

**Please also note** there are more configuration options for PHP-FPM. This post only shows the essential ones. For more
information, [please checkout the official documentation](http://php.net/manual/en/install.fpm.configuration.php).

---

PHP-FPM knows three different types for the process management (config value for `pm`):

1. _**dynamic**_ (as shown above)  
   You can configure how many child processes are always alive to immediately handle requests without an upstart time.
   This should be used when performance and quick responses are the focus of the application. That's why it is commonly 
   in combination with a webserver like nginx.  
   
2. _**ondemand**_ (see example below)  
   The configured amount of child processes will be started (only) as soon as the master process gets requests. After a certain amount of time
   these child processes will die and only the master process stays alive.  
   I personally prefer this mode for the background workers I described in my blog post, 
   because the quick response is not that important in this use-case.
   Depending on the workload you don't want to have a lot of idle processes consuming resources for nothing.  
   
3. _**static**_  
   You configure a fixed number of child processes. Until now I did't see a use-case for this.

---

<a name="adding-php-fpm-pools"></a>
#### Adding PHP-FPM pools

In my experimental system I used the following pool config to execute the workers in:

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

What did I do:

* I name the pool "commands".
* I defined a separate socket path (`/var/run/php/php7.1-fpm-commands.sock`) for this pool.  
  When using a network socket, make sure to use at least another port, e.g. `127.0.0.1:9001`.
* I set the process management mode to "ondemand".
* I allow a maximum of 5 child processes.  
  *So we can have at most 5 requests beeing executed in parallel.*
* I defined that child processes should die 10 seconds after they went idle.
* I defined a separate log file for all requests to this pool.  

To add this pool to your PHP-FPM simply place the file in the aforementioned path and reload/restart your PHP-FPM service.

Before reloading/restarting the php-fpm service make sure the access log file exists:

```bash
$ sudo mkdir -p /var/log/php
$ sudo touch /var/log/php/php7.1-fpm-commands.access.log
```

Now reload/restart the php-fpm service:

```bash
$ sudo service php7.1-fpm reload
# OR
$ sudo service php7.1-fpm restart
``` 

---

### Back to the question: How is parallelism achieved?

Now that you learned how PHP-FPM queues and handles requests in child processes, 
it should clear that the communication with PHP-FPM always consists of to actions:

1. **Write request to the PHP-FPM socket**, which will be queued and eventually executed in a child process.  
   As soon as PHP-FPM accepted the request and read its data, this action is finished and you can return to your code execution.  
   
2. **Read the response from the PHP-FPM socket**, which will be available as soon as the child process finished and 
   handed the execution result over to the PHP-FPM master process. The master process then writes the response back 
   to the PHP-FPM socket. This event can be observed by using the `stream_select` function of PHP which tells you that a 
   stream became active.

Given this, it is now possible to send multiple requests to PHP-FPM which will be executed in parallel and eventually 
handling their responses.

That is exactly what my [fast-cgi-client](https://github.com/hollodotme/fast-cgi-client) implementation offers when  
["Sending multiple requests and reading their responses (reactive)"](https://github.com/hollodotme/fast-cgi-client#sending-multiple-requests-and-reading-their-responses-reactive).  

---

[<i class="fa fa-coffee"></i>░░░░░░░░░░░░░░░░░░░░░░<i class="fa fa-beer"></i>] 2 hours | <small>17/11/2017</small>
