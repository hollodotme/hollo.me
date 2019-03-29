### Let's begin...

...with the history of the `v2.5.0` release.

In February 2019 [Arne Blankerts](https://twitter.com/arneblankerts) reached out to me via twitter DM and we 
had a discussion about the evergreen problem of the fast-cgi-client library that any relative script path or 
any script path containing path traversal characters lead to a (silent) fail of the FastCGI request. 

Some example paths that do not work when requesting a script on php-fpm, which is the focus FastCGI server 
that the library is developed against:

* `./relative/path/to/script.php`
* `../relative/path/to/script.php`
* `/absolute/path/with/../to/script.php`

Requesting such paths will lead to a `File Not Found` response from php-fpm, even though they might exist and would 
resolve to absolute paths in the file system. However, php-fpm does not do any automatic path resolution and expects 
an absolute path to the script that shall be executed. Full stop.

At that time `v2.4.3` was the most recent version of the library and there already was a hint on the trouble shooting 
section of the [README.md](https://github.com/hollodotme/fast-cgi-client/blob/v2.4.3/README.md#trouble-shooting) regarding 
this kind of issue. Arne's primary question was:
 
> **From user perspective:   
> Shouldn't the library be responsible to handle this problem in any better way   
> than silently returning a supposedly successful response?**

Well, that would be great, right?! So I agreed.

I then had a look into the code because I was wondering why the response was not the original error message from php-fpm 
which is "Primary script unknown" in this case. After some testing and step-debugging I realized that there was 
a general bug in the response handling of the underlying Socket class. The FastCGI protocol separates [STDOUT and STDERR 
byte streams](http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html#S5.3) when sending response packets, but the handling 
of the client at that time simply concatenated both streams to compose the complete server response. This resulted in responses 
that looked like this:

```text
Primary script unknownStatus: 404 Not Found
X-Powered-By: PHP/7.3.1

File not found
``` 

The response object that the client provides for each request than made the error messages disappear in 
the first response header as it assumes all lines before the blank line to be a header that was sent from the server. 
So the header array of the parsed response looked like this:

```php
[
    'Primary script unknownStatus' => '404 Not Found',
    'X-Powered-By' => 'PHP/7.3.1'
]
```
 
I slaped myself and called me names! -.-

So I decided to save the STDERR stream to a separate variable and throw a newly introduced exception whenever this 
variable is not empty. That solved two problems:

1. The server response that gets parsed by the response class does not contain (and therefor hide) any prepended error messages.
2. In case the server sent an error an exception is thrown that could be gracefully handled by the library user.

And that was basically the change that made it into the [`v2.5.0` release](https://github.com/hollodotme/fast-cgi-client/blob/v2.5.0/CHANGELOG.md#250---2019-01-29). 

### A short time later ...

... [Andy Buckingham](https://twitter.com/andybee) opened an [issue on GitHub](https://github.com/hollodotme/fast-cgi-client/issues/27) 
and stated that fetching custom output from e.g. `error_log()` is not acceccible on the client side anymore, because all
output from the STDERR stream is converted into an exception.

To summarize the whole discussion shortly: I tried to find a reliable way of identifying an error produced by the FastCGI server, 
so the client could be able to tell the user "Hey something went wrong, please have a look!".
 
php-fpm, which is only one FastCGI server implementation, tosses exactly 4 errors at the user in different circumstances as you can 
[see here](https://github.com/hollodotme/fast-cgi-client/issues/27#issuecomment-460990063). But that might be different 
for [other FastCGI server implementations](https://github.com/hollodotme/fast-cgi-client/issues/27#issuecomment-461004495).

Arne suggested to provide the status code from the equally named response header, but unfortunately those headers are not 
part of the FastCGI protocol (but of HTTP) and php-fpm does not always set such a status header as 
[explained here](https://github.com/hollodotme/fast-cgi-client/issues/27#issuecomment-461034287).

So in the end there was the following conclusion for the next `v2.6.0` release:

First and foremost, introducing the `ProcessManagerException` was a bad idea in the first place.

* The pass through callback function declaration will get a second argument:
  ```php
  $callback = function( string $outputBuffer, string $errorBuffer ) {};
  ```
  (The pass through callback can be used to pass all server-sent output packets directly to the client instead of waiting for a fully composed response.)
  
* The Response class will get two new methods `getOutput()` (which is identical to `getRawResponse()` and returns 
  the complete output from the STDOUT stream) and `getError()` which provides the complete output from the STDERR stream.

* `Response#getRawResponse()` will be deprecated in favour of consistent naming, and removed in `v3.0.0`.

* The `ProcessManagerException` introduced in `v2.5.0` will be removed.

From a strict point of view the last point is a BC break, as users may have implemented an appropriate exception handling 
after they upgraded to `v2.5.0`, but after considering all the arguments from Andy, Arne and me, it was simply a wrong move 
to introduce this exception as it leads to uninteded behaviour for another common use case (retrieval of STDERR output).   

You can find the [new `v2.6.0` release here](https://github.com/hollodotme/fast-cgi-client/releases/tag/v2.6.0).

### So how to handle server-sent errors now?

Let's stick to the initial problem of using a non-absolute script path for a request to php-fpm:

```php
<php declare(strict_types=1);

namespace YourVendor\YourProject;

use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\GetRequest;
use hollodotme\FastCGI\SocketConnections\NetworkSocket;

$connection = new NetworkSocket('127.0.0.1', 9000);
$client     = new Client($connection);
$request    = new GetRequest('../relative/path/to/script.php', '');

$response = $client->sendRequest($request);
```

The response can be checked as follows for the error.

#### 1. Check the error output for specific error messages

```php
if (preg_match("#^Primary script unknown\n?$#", $response->getError()))
{
    throw new LogicException('The script cannot be found on FastCGI server, please check if path is absolute.');
}
```

Please note the optional `\n?` in the regex. In PHP versions prior to 7.3 error messages from php-fpm have a trailing 
line feed, since 7.3 they have not. (Had to find that out the hard way. -.-)

#### 2. Check for the status header

```php
if ('404 Not Found' === $response->getHeader('Status'))
{
    throw new LogicException('The script cannot be found on FastCGI server, please check if path is absolute.');
}
```

Please note, that such a header is not always sent if an error occurrs. 
But in this error case you can rely on it with php-fpm as server.

And please be also aware that all server error scenarios can be user-generated on server side, like this:

```php
<?php declare(strict_types=1);

error_log('Primary script unknown');
header('Status: 404 Not Found');
echo 'File not found.';
```

---

I hope I could clarify the library changes from `v2.4.3` to `v2.6.0` and you still enjoy using the lib.

Please report any issues via GitHub at [hollodotme/fast-cgi-client](https://github.com/hollodotme/fast-cgi-client).

Thank you!

<small>03/29/2019</small>