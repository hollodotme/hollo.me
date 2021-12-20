---
layout: posts
title: Upgrade a PostgreSQL database
subtitle: with docker
tags: [postgres, PostreSQL, docker, docker-compose, database, upgrade]
permalink: /devops/upgrade-postgresql-database-with-docker.html
slug: devops
---

## Table of contents

* [The problem](#the-problem)
* [The solution](#the-solution)
* [Summary](#summary)

## The problem

I have a PostgreSQL version 12 instance running in production using an [alpine docker image](https://hub.docker.com/_/postgres?tab=tags&page=1&name=12-alpine).
Now I want to upgrade to PostgreSQL version 14, again by using an [alpine docker image](https://hub.docker.com/_/postgres?tab=tags&page=1&name=14-alpine).

Simply replacing the docker image will not work and gladly lead to a startup error of the postgres-container (which is a good thing!):

```log
postgres_1     | 2021-12-20 14:47:44.547 UTC [1] FATAL:  database files are incompatible with server
postgres_1     | 2021-12-20 14:47:44.547 UTC [1] DETAIL:  The data directory was initialized by PostgreSQL version 12, which is not compatible with this version 14.1.
```

According to the [official documentation](https://www.postgresql.org/docs/current/pgupgrade.html) it is recommended to 
use the `pg_upgrade` command line tool that is shipped with every PostgreSQL installation.

But this tool's synopsis states:

```bash
pg_upgrade -b oldbindir -B newbindir -d oldconfigdir -D newconfigdir [option...]
```

So I would need the directoryies of both, the old (version 12) and the new (version 14) PostgreSQL binary in order to run this command.

As I have separate docker images for each version that contain the binaries I would have to make the directory of the version 12 image available in the version 14 image.  This is doable of course but would take some time and probably some sophisticated docker skills.

## The solution

The good thing about SQL databases is that you can always dump them to a single file which you can import back into another instance.

So my goto solution were the following steps:

1. Dump the whole database to a file using the command line tool `pg_dumpall` on the version 12 docker image.
2. Shut down the database container and remove the volume that is mounted to the docker image.
3. Change the image version and bring up the new PostgreSQL container with a freshly initialized volume.
4. Import the dumped file using the command line tool `psql` on the verion 14 docker image.

### 1. Dump the whole database to a file with `pg_dumpall`

My service configuration in the `docker-compose.yml` looks like this:

```yaml
services:
  postgres:
    image: library/postgres:12-alpine       # Version 12 alpine image
    restart: 'unless-stopped'
    volumes:
      - postgres:/var/lib/postgresql/data   # This is where the database data is stored - a docker volume
      - ./backup:/backup                    # This is the mounted location to where I'll dump the database
    secrets:
      - pgsql-user
    environment:
      POSTGRES_USER: 'my-postgres-user'
      POSTGRES_PASSWORD_FILE: '/run/secrets/pgsql-user'
      POSTGRES_DB: 'MyDatabase'             # I use a custom default database
      PGDATA: '/var/lib/postgresql/data/pgdata'

volumes:
  postgres:

secrets:
  pgsql-user:
    file: ./config/secrets/pgsql-user.secret
```

The important parts of this config have a comment.

The command to dump the whole database and meta information to a single file looks like this:

```shell
docker-compose exec postgres pg_dumpall -U "my-postgres-user" > /backup/2021-12-20-Backup.sql
```

### 2. Shut down the database container and remove the data volume

Next step is stopping the database by either:

```shell
docker-compose stop postgres
```

or stopping the whole setup by:

```shell
docker-compose down
```

Now I need to completely remove the volume that was used by the version 12 container. As you can see in the config above the volume has the name "postgres".

So the easiest way to find the corresponding volume is:

```shell
docker volume ls | grep postgres
```

Copy the right volume name and run:

```shell
docker volume rm <prefix>_postgres
```

The `<prefix>_` usually is the name of your project's directory or the custom project name that you can set with the `-p` option when using the `docker-compose` command.

This command removes the volume and all its contents.

### 3. Bring up the new version 14 PostgreSQL container with a freshly initialized volume

Now I change the service configuration in the `docker-compose.yml` so that the version 14 alpine image is used:

```yaml
services:
  postgres:
    image: library/postgres:14-alpine       # Version 14 alpine image
    restart: 'unless-stopped'
    volumes:
      - postgres:/var/lib/postgresql/data
      - ./backup:/backup
    secrets:
      - pgsql-user
    environment:
      POSTGRES_USER: 'my-postgres-user'
      POSTGRES_PASSWORD_FILE: '/run/secrets/pgsql-user'
      POSTGRES_DB: 'MyDatabase'
      PGDATA: '/var/lib/postgresql/data/pgdata'

volumes:
  postgres:

secrets:
  pgsql-user:
    file: ./config/secrets/pgsql-user.secret
```

Everything else in the config stays the same.

Now I start the new container by either running:

```shell
docker-compose start postgres
```

or bringing back up the whole setup with:

```shell
docker-compose up -d
```

The configured "postgres" volume will be automatically initialized by docker again and the new PostgreSQL container
will initialize new database files of version 14 in it. I have a blank PostgreSQL 14 instance now.

### 4. Import the dumped file using the command line tool `psql`

In order to get my data back into my blank database instance, I need to import the previously dumped file.

The following commands will do this:

```shell
# Log into the container's shell
docker-compose exec postgres sh
# Import the dump file using psql
/# psql -U "my-postgres-user" -d "MyDatabase" < /backup/2021-12-20-Backup.sql
```

After this was executed I had to set the user's password for my custom database again in order to access it from the outside.

```shell
You are now connected to database "postgres" as user "my-postgres-user"
postgres=#
postgres=# \c MyDatabase
You are now connected to database "MyDatabase" as user "my-postgres-user"
MyDatabase=#
MyDatabase=# ALTER USER my-postgres-user PASSWORD '********'; 
MyDatabase=# ALTER ROLE 
MyDatabase=# \q 
```

That's it.

## Summary

Instead of using the `pg_upgrade` command, I found it easier (in docker context) to dump, upgrade and import the 
database by using the following docker-compose commands:

```shell
# 1. Dump all data from the old database version to a single file
docker-compose exec postgres pg_dumpall -U "my-postgres-user" > /backup/2021-12-20-Backup.sql

# 2. Stop the container
docker-compose stop postgres
 
# 3. Remove the data volume
docker volume rm <prefix>_postgres

# 4. Change the image version in docker-compose.yml

# 5. Start the new container and initialize a blank database instance
docker-compose start postgres

# 6. Import the dumped file into the database instance
docker-compose exec postgres sh

/#  psql -U "my-postgres-user" -d "MyDatabase" < /backup/2021-12-20-Backup.sql

# 7. Switch to imported database and set the user's password

You are now connected to database "postgres" as user "my-postgres-user"
postgres=#
postgres=# \c MyDatabase
You are now connected to database "MyDatabase" as user "my-postgres-user"
MyDatabase=#
MyDatabase=# ALTER USER my-postgres-user PASSWORD '********'; 
MyDatabase=# ALTER ROLE 
MyDatabase=# \q
/# exit 
```

#### Sources

* [PostgreSQL pg_upgrade documentation](https://www.postgresql.org/docs/current/pgupgrade.html)
* [How to Upgrade Your PostgreSQL Version Using Docker by Rosé Postiga](https://betterprogramming.pub/how-to-upgrade-your-postgresql-version-using-docker-d1e81dbbbdf9)