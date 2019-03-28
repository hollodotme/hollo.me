### Let's begin...

...with the history of the `v2.5.0` release.

In February 2019 [Arne Blankerts](https://twitter.com/arneblankerts) reached out to me via twitter DM and we 
had a discussion about the evergreen problem of the fast-cgi-client library that any relative script path or 
any script path containing path traversal characters leads to a (silent) fail of the FastCGI request. 

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

1. The server response that gets parsed by the response class does not contain (and therefor hide) any appended error messages.
2. In case the server sent an error an exception is thrown that could be gracefully handled by the library user.

And that was basically the change that made it into the [`v2.5.0` release](https://github.com/hollodotme/fast-cgi-client/blob/v2.5.0/CHANGELOG.md#250---2019-01-29). 

### A pile of turtles