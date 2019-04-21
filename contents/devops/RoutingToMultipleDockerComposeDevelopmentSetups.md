[![traefik & docker-compose network schema](@baseUrl@/img/posts/traefik-routing-multi-docker-compose.svg)](@baseUrl@/img/posts/traefik-routing-multi-docker-compose.svg)

---

### Prerequisites

To follow this tutorial, I assume that you have:

* [docker] installed on your machine
* [docker-compose] installed on your machine

### Goals

The goals of this tutorial are:

* Set up _ONE_ traefik instance that handles SSL offloading and HTTP/S routing to multiple web development environments that run in docker-compose setups.
* Set up two web development environments with two (sub-)domains each and HTTPS support

---

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
~ $ cd ~/ssl
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

### CHAPTER 2: Set up traefik as reverse proxy

The reason I use [traefik] as the global reverse proxy here is that it is able to watch the docker daemon and 
automatically discover newly started services, e.g. by bringing up a new docker-compose setup.

Further traefik is able to react on frontend rules represented by labels in docker-compose configurations which makes it
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

#### Start traefik on system restart

The traefik service should be always available in order to quickly start working on your projects. 
So it makes sense to add it as an autostart item to your system.

As a mac user I can create a simple launch script and a `.plist` file that will be registered to `launchd`.

##### ~/traefik/autostart.sh

```bash
#!/bin/bash

# Open Docker, only if is not running
if (! docker stats --no-stream ); then
  # On Mac OS this would be the terminal command to launch Docker
  open /Applications/Docker.app
  # Wait until Docker daemon is running and has completed initialisation
  while (! docker stats --no-stream ); do
    # Docker takes a few seconds to initialize
    echo "Waiting for Docker to launch..."
    sleep 1
  done
fi

cd ~/traefik/
docker-compose up -d --force-recreate
``` 

##### ~/traefik/com.user.traefik-autostart.plist

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple Computer//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
   <key>Label</key>
   <string>com.user.traefik-autostart</string>
   <key>ProgramArguments</key>
   <array><string>/Users/hollodotme/traefik/autostart.sh</string></array>
   <key>RunAtLoad</key>
   <true/>
</dict>
</plist>
```

##### Add to launchd

```bash
~ $ cd ~/traefik
~/traefik $ cp com.user.traefik-autostart.plist ~/Library/LaunchAgents/com.user.traefik-autostart.plist
~/traefik $ launchctl load ~/Library/LaunchAgents/com.user.traefik-autostart.plist
```

Test it with 

```bash
$ launchctl start ~/Library/LaunchAgents/com.user.traefik-autostart.plist
```

[docker]: https://www.docker.com/products/docker-desktop
[docker-compose]: https://docs.docker.com/compose/install/
[traefik]: https://traefik.io
[Filippo Valsorda]: https://github.com/FiloSottile
[mkcert]: https://github.com/FiloSottile/mkcert#install
[global docker network named "gateway"]: https://github.com/containous/traefik/issues/3599
