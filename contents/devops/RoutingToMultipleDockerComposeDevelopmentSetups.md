[![traefik & docker-compose network schema](@baseUrl@/img/posts/traefik-routing-multi-docker-compose.svg)](@baseUrl@/img/posts/traefik-routing-multi-docker-compose.svg)

---

<nav role="doc-toc" style="width: 45%; float: right; border-left: 3px solid #efefef; padding-left: 15px;">
    <h3>Table of contents</h3>
    <ul>
        <li>
            <a href="#chapter-1">CHAPTER 1: Create valid SSL certificates</a>
        </li>
        <li>
            <a href="#chapter-2">CHAPTER 2: Set up traefik as reverse proxy</a>
        </li>
        <li>
            <a href="#digression-autostart-traefik">DIGRESSION: Autostart traefik on login</a>
        </li>
        <li>
            <a href="#chapter-3">CHAPTER 3: Development project setups</a>
        </li>
        <li>
            <a href="#chapter-4">CHAPTER 4: Domain resolution</a>
        </li>
        <li>
            <a href="#summary">SUMMARY</a>
        </li>
    </ul>
</nav>

### Prerequisites

To follow this tutorial, I assume that you have:

* [docker] installed on your machine
* [docker-compose] installed on your machine

### Goals

The goals of this tutorial are:

* Set up _ONE_ traefik instance that handles SSL offloading and HTTP/S routing to multiple web development environments that run in docker-compose setups.
* Set up two web development environments with two (sub-)domains each and HTTPS support

---

<a name="chapter-1"></a>
### CHAPTER 1: Create valid SSL certificates

Thanks to the work of [Filippo Valsorda] we can install/use the tool [mkcert] in order to create locally-trusted SSL certificates 
on our machine. `mkcert` installs a certificate authority (CA) to your local trust store and the mozilla firefox trust 
store (if available) to sign any certificate you want to generate for local development purposes.

#### How to download & install `mkcert`?

You can either follow one of the installation instruction for your operating system [using package managers here](https://github.com/FiloSottile/mkcert#installation) 
or download and install [pre-build binaries from here](https://github.com/FiloSottile/mkcert/releases).

#### How to create certificates?

In order to create a certificate(s) just run one of the following commands.

**HINT:** The name of the first domain name is used for the certificate filenames. 
To not confuse yourself, create a certificate for **only one main domain**.

##### Create a wildcard certificate for one domain

```bash
$ mkcert "*.the-domain.com"
```

This generates two files:

1. `_wildcard.the-domain.com.pem`
2. `_wildcard.the-domain.com-key.pem`

You should see output like this:

```text
Using the local CA at "/Users/hollodotme/Library/Application Support/mkcert" ✨

Created a new certificate valid for the following names 📜
 - "*.the-domain.com"

Reminder: X.509 wildcards only go one level deep, so this won't match a.b.the-domain.com ℹ️

The certificate is at "./_wildcard.the-domain.com.pem" and the key at "./_wildcard.the-domain.com-key.pem" ✅
``` 

##### Create specific subdomains certificates

```bash
$ mkcert "dev.the-domain.com" "api.the-domain.com"
``` 

This again generates only two files:

1. `dev.the-domain.com+1.pem`
2. `dev.the-domain.com+1-key.pem`

You should see output like this:

```text
Using the local CA at "/Users/hollodotme/Library/Application Support/mkcert" ✨

Created a new certificate valid for the following names 📜
 - "dev.the-domain.com"
 - "api.the-domain.com"

The certificate is at "./dev.the-domain.com+1.pem" and the key at "./dev.the-domain.com+1-key.pem" ✅
```

#### Where to place the certificates?

Your generated certificates are only valid on your local machine and therefore...

* should **not** be shared or committed to any project via VCS,
* should be stored on a central place, so you can use them everywhere.

A good place for example is: `~/ssl` in your local user's home directory.

<a name="generate-certificates-for-our-tutorial"></a>
#### Generate certificates for our tutorial

As shown on the picture above we want to have two web development setups that have two sub-domains each.

* dev.project1.com
* readis.project1.com
* dev.project2.com
* readis.project2.com

The following commands will create a wildcard certificate for each project:

```bash
$ cd ~/ssl
~/ssl $ mkcert "*.project1.com"
~/ssl $ mkcert "*.project2.com"
```

This should generate the following files:

```text
~/ssl
 |- _wildcard.project1.com-key.pem
 |- _wildcard.project1.com.pem
 |- _wildcard.project2.com-key.pem
 `- _wildcard.project2.com.pem
```

---

<a name="chapter-2"></a>
### CHAPTER 2: Set up traefik as reverse proxy

The reason I use [traefik] as the global reverse proxy here is that it is able to watch the docker daemon and 
automatically discover newly started services, e.g. by bringing up a new docker-compose setup.

Furthermore traefik is able to react on frontend rules represented by labels in docker-compose configurations which makes it
very easy to assign (sub-)domains to services, so traefik can route traffic to them.

#### How to configure traefik?

traefik needs one configuration file, named `traefik.toml`.   
The following basic configuration instructs traefik to:

* listen for HTTP (Port 80) and HTTPS (Port 443) on all local IP addresses (e.g. `127.0.0.1`)
* automatically redirect HTTP traffic to HTTPS
* use the listed SSL certificates for the SSL offloading

```toml
defaultEntryPoints = ["http", "https"]

[entryPoints]
    [entryPoints.http]
    address = ":80" 
        [entryPoints.http.redirect]
        entryPoint = "https"
    [entryPoints.https]
    address = ":443"
    [entryPoints.https.tls]
      [[entryPoints.https.tls.certificates]]
      certFile = "/etc/traefik/ssl/_wildcard.project1.com.pem"
      keyFile = "/etc/traefik/ssl/_wildcard.project1.com-key.pem"
      [[entryPoints.https.tls.certificates]]   # repeat this block to add more SSL certificates
      certFile = "/etc/traefik/ssl/_wildcard.project2.com.pem"
      keyFile = "/etc/traefik/ssl/_wildcard.project2.com-key.pem"

[api]

[docker]
```

As you can see I referenced the four SSL certificate files I generated in [CHAPTER 1](#generate-certificates-for-our-tutorial).

All you have to do is to replace the names of the SSL certificate files with your actual generated filenames.

You may wonder why the given paths to SSL certificate files is `/etc/traefik/ssl/`. This is because we will use traefik
as a docker container and will mount the local `~/ssl` directory to the container's `/etc/traefik/ssl/` directory as you'll see in a second. 

#### How to start traefik?

In order to avoid a long and complex single docker command, we'll use a `docker-compose` configuration to set up the 
traefik instance. This way you can easily (re)start and stop the traefik instance.

Before weg get to the docker-compose setup, we need to create a [global docker network named "gateway"]. 
The big blue box in the image above refers to this global network "gateway".
All other docker-compose setups will later attach to this network with their public backends, e.g. their webservers.

<a name="chapter-2-create-gateway-network"></a>
#### Create the gateway network

```bash
$ docker network create \
  --driver=bridge \
  --attachable \
  --internal=false \
  gateway
```

The important part here is the option `--internal=false`.

**Note:** This network will survive a restart of the docker daemon or your machine and must be created only once. 

#### docker-compose.yml

The following `docker-compose.yml` should be ready to use, if you put your SSL certificates to `~/ssl` 
as in [CHAPTER 1](#generate-certificates-for-our-tutorial).

```yaml
version: "3"

services:
  traefik:
    image: traefik
    container_name: global_traefik
    restart: "always"
    ports:
      # Port 443 is used for HTTP trafic
      - "80:80"
      # Port 443 is used for HTTPS trafic
      - "443:443"
      # Port 8080 is used for traefik's own dashboard
      - "8080:8080"
    volumes:
      # Here is the mount of the traefik config
      - ./traefik.toml:/etc/traefik/traefik.toml:ro
      # Here is the mount of the local ~/ssl directory
      - ~/ssl:/etc/traefik/ssl:ro
      # The docker socket is mounted for auto-discovery of new services
      - /var/run/docker.sock:/var/run/docker.sock:ro
    networks:
      # Attach the traefik container to the default network (which is the global "gateway" network)
      - default

# Make the externally created network "gateway" available as network "default"
networks:
  default:
    external: 
      name: gateway
```

#### Bring it up

Now start the traefik instance with the following command:

```bash
$ docker-compose up -d
```

You can check, if the traefik instance is properly running with:

```bash
$ docker-compose ps
```

This should give the following output:

```text
     Name        Command    State                                Ports
----------------------------------------------------------------------------------------------------
global_traefik   /traefik   Up      0.0.0.0:443->443/tcp, 0.0.0.0:80->80/tcp, 0.0.0.0:8080->8080/tcp
```

#### Check the traefik dashboard

traefik comes with a built-in dashboard showing you all container instances that are running, their health 
and how they could be accessed.

**Open:** http://127.0.0.1:8080/dashboard/ 

You should see something like this:

[![traefik dashboard](@baseUrl@/img/posts/traefik-dashboard.png)](@baseUrl@/img/posts/traefik-dashboard.png)

#### Where to place the traefik configs?

In order to use traefik as a global reverse proxy on your machine all the files should be placed in a central place.
A good place to put them is `~/traefik` in your local user's home directory, alongside with your local SSL certificates.

```text
~
|- /ssl
|  |- _wildcard.the-domain.com.pem
|  `- _wildcard.the-domain.com-key.pem
`- /traefik
   |- docker-compose.yml
   `- traefik.toml
```

---

<a name="digression-traefik-autostart"></a>
### DIGRESSION: Autostart traefik on login

The traefik service should be always available in order to quickly start working on your projects. 
So it makes sense to add it as an autostart item to your system.

As a Mac user I can create a shell script and a `.plist` file that will be registered to OSX' `launchd`.

#### ~/traefik/autostart.sh

```bash
#!/bin/bash

DOCKER_APP=/Applications/Docker.app
DOCKER="/usr/local/bin/docker"
DOCKER_COMPOSE="/usr/local/bin/docker-compose"
TRAEFIK_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

echo ${TRAEFIK_DIR}

# Create global gateway network, if not exists
${DOCKER} network create --driver bridge --attachable --internal=false gateway || true

# Open Docker, only if is not running
if (! ${DOCKER} stats --no-stream ); then
  # Start Docker.app
  open ${DOCKER_APP}
  # Wait until Docker daemon is running and has completed initialisation
  while (! ${DOCKER} stats --no-stream ); do
    # Docker takes a few seconds to initialize
    echo "Waiting for Docker to launch..."
    sleep 1
  done
fi

cd ${TRAEFIK_DIR}

${DOCKER_COMPOSE} up -d --force-recreate
``` 

#### ~/Library/LaunchAgents/com.user.traefik.autostart.plist

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
	<dict>
		<key>Label</key>
		<string>com.user.traefik.autostart</string>
		<key>Program</key>
		<string>/Users/hollodotme/traefik/autostart.sh</string>
		<key>RunAtLoad</key>
		<true/>
		<key>StandardErrorPath</key>
  		<string>/Users/hollodotme/Library/Logs/traefik.autostart.log</string>
  		<key>StandardOutPath</key>
  		<string>/Users/hollodotme/Library/Logs/traefik.autostart.log</string>
  		<key>WorkingDirectory</key>
  		<string>/Users/hollodotme/traefik</string>
	</dict>
</plist>
```

##### Load the service with launchd

```bash
$ launchctl load ~/Library/LaunchAgents/com.user.traefik.autostart.plist
```

The `autostart.sh` should be executed immediately after the previous command, so you can check the output of the script 
in the specified log file: 

```bash
$ tail -F ~/Library/Logs/traefik.autostart.log
```

You can find my complete traefik autostart configuration in 
[this git repository](https://github.com/hollodotme/traefik-proxy-autostart). 
Feedback and improvements are always welcome. 

---

<a name="chapter-3"></a>
### CHAPTER 3: Development project setups

In order to demonstrate the routing into different development setups using traefik, I set up two identical docker-compose
configurations that only differ in:

* the name of the internal network they are creating/using
* the name of the domain under which they are reachable
* the output of their startpage

A typical web project setup that I use consists of the following components/services/containers (as you can see in the yellow boxes of the picture above):

* [nginx] as webserver
* [php-fpm] as application server
* [MariaDB] as persitent data storage
* [redis] as session storage and in-memory cache
* [re<sup>a</sup>dis] as web UI for redis
* [composer] for installing dependencies

#### File listings of project1 & project2

<div style="float: left; width: 45%;">
<pre class="language-text"><code class="language-text">/path/to/project1
|- /.docker
|  |- /nginx
|  |  `- /default.conf
|  |- /php
|  |  `- Dockerfile
|  `- /readis
|     |- /app.php
|     `- /servers.php
|- /data
|  `- /mariadb
|- /public
|  `- /index.php
`- docker-compose.yml</code></pre>
</div>

<div style="float: right; width: 45%;">
<pre class="language-text"><code class="language-text">/path/to/project2
|- /.docker
|  |- /nginx
|  |  `- /default.conf
|  |- /php
|  |  `- Dockerfile
|  `- /readis
|     |- /app.php
|     `- /servers.php
|- /data
|  `- /mariadb
|- /public
|  `- /index.php
|- composer.json
`- docker-compose.yml</code></pre>
</div>

<br style="clear: both">

I'll go through these files one by one for project1. The files for project2 look exactly the same beside the fact that
"project1" is replaced with "project2" everywhere.

#### project1/docker-compose.yml

This is the full docker-compose configuraton for project1.

```yaml
version: "3"

services:
  nginx:
    image: nginx
    container_name: "project1_nginx"
    volumes:
    - ./:/app:ro
    - ./.docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    restart: "always"
    labels:
      - "traefik.frontend.rule=HostRegexp:{subdomain:[a-z]+}.project1.com"
    networks:
    - default
    - project1

  php:
    build: 
      dockerfile: Dockerfile
      context: ./.docker/php
    container_name: "project1_php"
    volumes:
    - ./:/app
    restart: "always"
    networks:
    - project1

  db:
    image: mariadb:10.2
    container_name: "project1_db"
    restart: "always"
    environment:
      MYSQL_ROOT_PASSWORD: root
    volumes:
    - ./data/mariadb:/var/lib/mysql
    networks:
    - project1

  redis:
    image: redis
    container_name: "project1_redis"
    restart: "always"
    networks:
      - project1

  readis:
    image: hollodotme/readis
    container_name: "project1_readis"
    restart: "always"
    volumes:
      - ./.docker/readis:/code/config:ro
    networks:
      - project1
        
  composer:
      image: composer
      container_name: "project1_composer"
      restart: "no"
      volumes:
        - ./:/app
      networks:
        - default
      command: "update -o -v"

networks:
  default:
    external:
      name: gateway

  project1:
    internal: true
``` 

##### Network configuration

The configuration defines two networks for the set up. The first network "default" is the global "gateway" network that we
created externally as described in [CHAPTER 2](#chapter-2-create-gateway-network). It is represented by the big blue box in the picture above. 

The second network "project1" is an internal network created specifically for this project, so all the project's services 
can communicate with each other but keep encapsulated in a private network, disconnected from the outside world. 
It is represented by the yellow boxes in the picture above.

```yaml
networks:
  default:
    external:
      name: gateway

  project1:
    internal: true
```

The webserver of the application is - as the only exception - connected to both networks, so we can route traffic 
from the outside into the application. If we look at the nginx service configuration there are two notable things:

```yaml
  nginx:
    image: nginx
    container_name: "project1_nginx"
    volumes:
    - ./:/app:ro
    - ./.docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    restart: "always"
    labels:
      - "traefik.frontend.rule=HostRegexp:{subdomain:[a-z]+}.project1.com"
    networks:
    - default
    - project1
```

First, under `networks:` both defined networks are listed, which means nginx can communicate with whatever is in the 
"default" (a.k.a. "gateway") network and whatever is in the "project1" network.

Second, under `labels:` a frontend rule for traefik was defined, saying that traefik should route all traffic for 
`*.project1.com` to this nginx instance. This is basically how the domains are assigned to the development projects.

The `composer` service is connected only to the "default" (a.k.a. "gateway" network) because it needs internet access 
which is not avaialable from the internal network "project1". On the other hand the composer service does not need any 
connection to the other containers of the setup, but the filesystem mount. 

#### project1/composer.json

Just a more or less empty composer json, so that the composer service has something to do.

```json
{
  "name": "traefik-routing/project1",
  "description": "Example project 1 for reverse-proxy routing using traefik",
  "license": "MIT",
  "require": {},
  "require-dev": {
    "roave/security-advisories": "dev-master"
  }
}
```

#### project1/.docker/nginx/default.conf

The configuration for the nginx webserver looks as follows:

```nginx
server {
    listen 80;
    server_name dev.project1.com;

    root /app/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }
}

server {
    listen 80;
    server_name readis.project1.com;

    location / {
        proxy_pass http://readis:80;
    }
}
```

As you can see there are two servers/sub-domains defined:

* `dev.project1.com` passes traffic via FastCGI to the php-fpm instance on port 9000 - the `php` service in the docker-compose setup.  
  The important line is:
  ```nginx
  fastcgi_pass php:9000;
  ```

* `readis.project1.com` passes HTTP traffic to the re<sup>a</sup>dis instance on port 80 - the `readis` service in the docker-compose setup.
  The important line is:
  ```nginx
  proxy_pass http://readis:80;
  ```
  
Both defined servers only listen on port 80, because the SSL offloading will be done by traefik centrally, 
which then sends traffic to its backends unencrypted via HTTP on port 80.

#### project1/.docker/php/Dockerfile

This is the docker image configuration file that is used to build the php-fpm image with some needed extensions. 
In this case we especially need the `pdo_mysql` and `redis` extension ([phpredis]) in order to connect to the MariaDB and redis instances.

And as we are in development environment I usually also enable [OPCache] and install [Xdebug].

```dockerfile
FROM php:7.3-fpm-alpine
ENV PHPREDIS_VERSION 4.3.0
ENV XDEBUG_VERSION 2.7.1
# Update system
RUN apk update && apk upgrade && apk add --no-cache ${PHPIZE_DEPS} procps \
    && pecl install xdebug-${XDEBUG_VERSION} \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-configure opcache --enable-opcache \
    && docker-php-ext-install opcache pdo_mysql
# Install redis extension
RUN mkdir -p /usr/src/php/ext/redis \
   && curl -L https://github.com/phpredis/phpredis/archive/${PHPREDIS_VERSION}.tar.gz | tar xvz -C /usr/src/php/ext/redis --strip 1 \
   && echo 'redis' >> /usr/src/php-available-exts \
   && docker-php-ext-install redis
# Cleanup
RUN apk del ${PHPIZE_DEPS} \
    && rm -rf /var/cache/apk/*
WORKDIR /app
```

#### project1/.docker/readis/app.php

This config file is required by re<sup>a</sup>dis to set up the base URL of the redis web UI.

```php
<?php

return [
	'baseUrl' => 'https://readis.project1.com',
];
```   

#### project1/.docker/readis/servers.php

This config file is required by re<sup>a</sup>dis to set up the available redis server instances for the web UI.

**Please note** that the host name must be the service name "redis" that was defined in the `docker-compose.yml`.

```php
<?php

return [
	[
		'name'          => 'Redis-Server Project 1',
		'host'          => 'redis',
		'port'          => 6379,
		'auth'          => null,
		'timeout'       => 2.5,
		'retryInterval' => 100,
		'databaseMap'   => [
		    0 => 'Project 1 sessions',	
        ],
	],
];
```

#### project1/public/index.php

This is the actual test application that will access the database and store sessions in redis.
In order to keep it simple I just connect to both services ("db" & "redis") and echo some data.

```php
<?php declare(strict_types=1);

ini_set( 'session.name', 'SIDP1' );
ini_set( 'session.save_handler', 'redis' );
ini_set( 'session.save_path', 'tcp://redis:6379?weight=1&database=0' );
ini_set( 'session.gc_maxlifetime', '84400' );

session_set_cookie_params( 84400, '/', 'dev.project1.com', true, true );

session_start();

$pdo = new PDO('mysql:host=db;port=3306', 'root', 'root');
$statement = $pdo->query("SELECT 'This is the DB of project 1'");

header('Content-Type: text/html; charset=utf-8', true, 200);
printf('<h1>%s</h1>', $statement->fetchColumn() );
printf('<p>Your session ID is: %s=%s</p>', session_name(), session_id());
```

#### Bring up project1

Now let' start the docker-compose setup for project 1 with the following command.

```bash
$ cd /path/to/project1
/path/to/project1 $ docker-compose up -d
```

Check with `docker-compose ps` if all containers are up and running. You should see something like this:

```text
      Name                     Command               State    Ports
--------------------------------------------------------------------
project1_composer   /bin/sh /docker-entrypoint ...   Exit 1
project1_db         docker-entrypoint.sh mysqld      Up
project1_nginx      nginx -g daemon off;             Up       80/tcp
project1_php        docker-php-entrypoint php-fpm    Up
project1_readis     docker-php-entrypoint php  ...   Up
project1_redis      docker-entrypoint.sh redis ...   Up
```

As soon as your setup is up you can check the traefik dashboard again and should see that there had a new 
frontend and backend been discovered.

[![traefik dashboard with project 1 running](@baseUrl@/img/posts/traefik-dashboard-project1.png)](@baseUrl@/img/posts/traefik-dashboard-project1.png)

#### Bring up project2

Now we can also start project 2 and should see the same effect in the traefik dashboard.

```bash
$ cd /path/to/project2
/path/to/project2 $ docker-compose up -d
```

Check with `docker-compose ps` if all containers are up and running. You should see something like this:

```text
      Name                     Command               State    Ports
--------------------------------------------------------------------
project2_composer   /bin/sh /docker-entrypoint ...   Exit 1
project2_db         docker-entrypoint.sh mysqld      Up
project2_nginx      nginx -g daemon off;             Up       80/tcp
project2_php        docker-php-entrypoint php-fpm    Up
project2_readis     docker-php-entrypoint php  ...   Up
project2_redis      docker-entrypoint.sh redis ...   Up
```

Now the traefik dashboard should look like this:

[![traefik dashboard with project 1 & 2 running](@baseUrl@/img/posts/traefik-dashboard-project2.png)](@baseUrl@/img/posts/traefik-dashboard-project2.png)

You can find both example projects in [this git repository](https://github.com/hollodotme/treafik-proxy-projects).

**We are almost there!**

<a name="chapter-4"></a>
### CHAPTER 4: Domain resolution

In order to open the four sub-domains that we have configured we need to make sure that they will be resolved locally, 
so traefik can pick up the requests.

The easiest way to do this is put all four domains in your `/etc/hosts` file once and point them to `127.0.0.1`.

```text
127.0.0.1   dev.project1.com readis.project1.com
127.0.0.1   dev.project2.com readis.project2.com
``` 

Now we're ready to go!

* https://dev.project1.com
  [![dev.project1.com](@baseUrl@/img/posts/dev-project1-com.png)](@baseUrl@/img/posts/dev-project1-com.png)

* https://readis.project1.com/server/0/
  [![readis.project1.com](@baseUrl@/img/posts/readis-project1-com.png)](@baseUrl@/img/posts/readis-project1-com.png)

* https://dev.project2.com
  [![dev.project2.com](@baseUrl@/img/posts/dev-project2-com.png)](@baseUrl@/img/posts/dev-project2-com.png)

* https://readis.project2.com/server/0/
  [![readis.project2.com](@baseUrl@/img/posts/readis-project2-com.png)](@baseUrl@/img/posts/readis-project2-com.png)

---

<a name="summary"></a>
### SUMMARY

What we have accomplished:

* We can create locally-trusted SSL certificates with [mkcert].
* We set up a global reverse-proxy with [traefik] that centrally handles SSL offloading and we can attach any HTTP server to it by connecting to a gateway network and setting a simple label in a docker-compose configuration.
* We set up an autostart mechanism for the traefik instance.
* We started two independent web projects in docker-compose setups and made their sub-domains available through traefik.

When starting a new project the following steps have to be taken (once):

1. Create a new SSL certificate using `mkcert` an place it to `~/ssl`.
   ```bash
   ~/ssl $ mkcert "*.new-domain.com"
   ```
2. Reference the newly created SSL files in `~/traefik/traefik.toml` 
   ```toml
   [[entryPoints.https.tls.certificates]]
   certFile = "/etc/traefik/ssl/_wildcard.new-domain.com.pem"
   keyFile = "/etc/traefik/ssl/_wildcard.new-domain.com-key.pem"
   ```
   and restart traefik.
   ```bash
   ~/traefik $ docker-composer up -d --force-recreate
   ```
3. Add the new (sub-)domain(s) to your `/etc/hosts`.
   ```text
   127.0.0.1   sub.new-domain.com
   ```
4. Add the "default" (a.k.a. "gateway") network and traefik label to the target service in your new `docker-compose.yml`
   ```yml
   labels:
     - "traefik.frontend.rule=HostRegexp:{subdomain:[a-z]+}.new-domain.com"
   networks:
     - default
     - projectNew
   ```
5. Fire up your new project with docker-compose and you're good to go.
   ```bash
   /path/to/new-project $ docker-compose up -d
   ```
   
---

<small>04/22/2019</small>

[docker]: https://www.docker.com/products/docker-desktop
[docker-compose]: https://docs.docker.com/compose/install/
[traefik]: https://traefik.io
[Filippo Valsorda]: https://github.com/FiloSottile
[mkcert]: https://github.com/FiloSottile/mkcert#install
[global docker network named "gateway"]: https://github.com/containous/traefik/issues/3599
[nginx]: https://nginx.org
[php-fpm]: https://php.net/fpm
[MariaDB]: https://mariadb.org
[redis]: https://redis.io
[re<sup>a</sup>dis]: https://github.com/hollodotme/readis
[composer]: https://getcomposer.org
[phpredis]: https://github.com/phpredis/phpredis
[OPCache]: https://php.net/opcache
[Xdebug]: https://xdebug.org