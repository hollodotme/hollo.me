## The problem

Many PHP applications expect/require a configuration by injecting a config object.
The more complex the application is, the more extensive the need for configuration gets.

Every config value influences the behaviour of the application in some kind.

Furthermore for an easy start many applications provide a default configuration, 
where only few values have to be adapted to use the application.
 
In case of object-oriented configurations there are more or less only two
possibilities to create such a configuration for the individual project:

1. Extending a default configuration class and overwrite all the needed methods
2. Implementing the interface the application requires for configuration

The first variant creates a very strong binding to the default config class 
which is maybe undesirable or even not possible in some cases. 

By publishing a configuration interface the application provides the freedom
of self-implementing a class to fulfill its configuration needs. 
So why then abandon that freedom by extending a class?

But by using such an interface it becomes necessary to implement **all** of its required methods, 
even though just a part of the configuration needs to be adapted. 

To clarify this in code lets assume our application requires the following interface for its configuration:

**HINT:** Code is provides in php7 syntax.

```php
<?php

namespace SomeCompany\Application\Interfaces;

interface ConfiguresApplication
{
    public function getName() : string;
    
    public function getUrl() : string;
    
    public function getLogger() : LogsActivity;
    
    public function getErrorHandler() : HandlesError; 
}
```

You can see that this interface requires 2 simple string values (name and url), 
alongside with 2 complex values (logger and error handler) which have required interfaces by themselves.

For the sake of completeness and for better comprehension of the following 
code these interfaces may be defined like this:

```php
<?php

namespace SomeCompany\Application\Interfaces;

interface LogsActivity
{
    public function log( string $message, array $context = [ ] );
}
```

and

```php
<?php

namespace SomeCompany\Application\Interfaces;

interface HandlesError
{
    public function handleError( \Throwable $throwable );
}
```

---

#### Requirements

The adaption of name and url are mandatory for using the application.
Configuring an own logger and/or error handler is optional and is for advanced usage.  

The application expects the configuration to be injected to the constructor:

```php
<?php

namespace SomeCompany\Application;

use SomeCompany\Application\Interfaces\ConfiguresApplication;

class Application
{
    /** @var ConfiguresApplication */
    private $config;
    
    public function __construct( ConfiguresApplication $config )
    {
        $this->config = $config;
    }
    
    /**
     * Methoden, die die Config verwenden.
     */
}
```

---

### 1. Extending a default configuration class

A default configuration class provided by the application could look like this:

```php
<?php

namespace SomeCompany\Application\Defaults;

use SomeCompany\Application\Interfaces\ConfiguresApplication;

class DefaultApplicationConfig implements ConfiguresApplication
{
    public function getName() : string
    {
        return 'Unnamed application';
    }
    
    public function getUrl() : string
    {
        return 'http://example.com';
    }
    
    public function getLogger() : LogsActivity
    {
        return new class implements LogsActivity
        {
            public function log( string $message, array $context )
            {
                /** I am a NullLogger, do nothing */
            }
        };
    }
    
    public function getErrorHandler() : HandlesError
    {
        return new class implements HandlesError
        {
            public function handleError( \Throwable $throwable )
            {
                throw $throwable;
            }
        };
    }
}
```

Let's again have a look at our requirements: 

 * Name and url **must** be configured and
 * logger and error handler **can** be configured

This default configuration class comes up with a problem.
It provides default values for name and url. So the user is not forced to
configure these values. Furthermore these default values have no valid use-case.

That means if we extend this class we have to know that the methods for
name and url must be overwritten.

The following configuration would be accepted by the application even 
though it does not meet the requirements:

```php
<?php

namespace MyCompany\MyApplication\Configs;

use SomeCompany\Application\Defaults\DefaultApplicationConfig;

class MyApplicationConfig extends DefaultApplicationConfig
{

}
```

---

### 2. Implementing the configuration interface

Now let's have a look at alternatively implementing the `ConfiguresApplication` interface.
As mentioned above we are now forced to implement all of its required methods.

```php
<?php

namespace MyCompany\MyApplication\Configs;

use SomeCompany\Application\Interfaces\ConfiguresApplication;

class MyApplicationConfig implements ConfiguresApplication
{
    public function getName() : string
    {
        return 'My application';
    }
    
    public function getUrl() : string
    {
        return 'https://www.my-application.com';
    }
    
    public function getLogger() : LogsActivity
    {
        return new class implements LogsActivity
        {
            public function log( string $message, array $context )
            {
                /** I am a NullLogger, do nothing */
            }
        };
    }
    
    public function getErrorHandler() : HandlesError
    {
        return new class implements HandlesError
        {
            public function handleError( \Throwable $throwable )
            {
                throw $throwable;
            }
        };
    }
}
```

This variant shows us the other side of the problem. In comparison to 
extending the default configuration class we now need to impement the methods 
(`getLogger` and `getErrorHandler`) that were declared optional by the requirements.

So how do we reach the goal to implement only what we need and use the standard parts of the configuration?

---

### 3. Providing traits

[In version 5.4.0 traits were introduced to PHP](http://php.net/trait), 
which allow to embed reusable code in classes and therefor provide parts of a class.
That's exactly what we need to solve our problem.

Given that the application has default configurations for logging and 
error handling it could provide the following traits:

```php
<?php

namespace SomeCompany\Application\Traits;

trait DefaultLogging
{
    public function getLogger() : LogsActivity
    {
        return new class implements LogsActivity
        {
            public function log( string $message, array $context )
            {
                /** I am a NullLogger, do nothing */
            }
        };
    }
}
```

and

```php
<?php

namespace SomeCompany\Application\Traits;

trait DefaultErrorHandling
{
    public function getErrorHandler() : HandlesError
    {
        return new class implements HandlesError
        {
            public function handleError( \Throwable $throwable )
            {
                throw $throwable;
            }
        };
    }
}
```

The default configuration class `DefaultApplicationConfig` mentioned above is
no longer provided by the application. Instead it only provides the `ConfiguresApplication`
interface and the two traits `DefaultLogging` and `DefaultErrorHandling`.

Now we are able to combine the must have configuration of values with
default configurations in one implementation of the config interface that meets perfectly the requirements.

---

### 4. Result: A "Traitful" config

The most simple configuration would look like this:

```php
<?php

namespace MyCompany\MyApplication\Configs;

use SomeCompany\Application\Interfaces\ConfiguresApplication;
use SomeCompany\Application\Traits\DefaultErrorHandling;
use SomeCompany\Application\Traits\DefaultLogging;

class MyApplicationConfig implements ConfiguresApplication
{
    public function getName() : string
    {
        return 'My application';
    }
    
    public function getUrl() : string
    {
        return 'https://www.my-application.com';
    }
    
    use DefaultLogging;
    use DefaultErrorHandling;
}
```

As you can see we used a specific naming schema for our interfaces, traits and classes
which clarifies the kind of each used element. This improves the comprehensibility of our application components
and makes the code more readable.
 
### Overview naming schema
 
**Interfaces:**

 * ConfiguresApplication
 * LogsActivity
 * HandlesError
 
**Traits:**

 * DefaultLogging
 * DefaultErrorHandling
 
**Classes:**

 * ApplicationConfig
 * Logger
 * ErrorHandler
 
### Project structure

A typical project structure based on [composer](https://getcomposer.org) 
and [PSR-0](http://www.php-fig.org/psr/psr-0/) could look as follows.


```bash
- MyApplication
  |- ...
  |- src
  |  `- Configs
  |     `- MyApplicationConfig.php
  |- ...
  `- vendor
     `- SomeCompany
        `- Application
           |- Traits
           |  |- DefaultErrorHandler.php
           |  `- DefaultLogging.php
           |- Interfaces
           |  |- ConfiguresApplication.php
           |  |- HandlesError.php
           |  `- LogsActivity.php
           `- Application.php
```

---

You can find the complete example code under:

<div class="github-card" data-user="hollodotme" data-repo="traitful-configs" data-width="100%"></div>
<script src="//cdn.jsdelivr.net/github-cards/latest/widget.js"></script>

---

Related links / References:

* [PHP Traits](http://php.net/trait)
* [composer - php dependency manager](https://getcomposer.org)
* [Namespace Standard PSR-0](http://www.php-fig.org/psr/psr-0/)
* <i class="fa fa-github"></i> [hollodotme/traitful-configs](https://github.com/hollodotme/traitful-configs)

<small>03/12/2016</small>
