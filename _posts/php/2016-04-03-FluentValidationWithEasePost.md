---
layout: posts
title: Fluent validation with ease
tags: [valiation, fluid interface, PHP, code, OSS]
permalink: /php/fluent-validation-with-ease.html
slug: php
---
## The problem

Every developer knows (and probably hates) it: As soon as a web application needs to consume user input by a form or an API, this data needs to be validated befor further processing, persisting or displaying.
Of course, nature and extent depend on the complexity and mass of input data. But lets take a supposed simple example.
 
A website provides a form that requests the following user data:
 
* Firstname,
* Lastname,
* Email address and
* optionally the birthdate

This form could look like this:

```html
<form method="post" action="/user/change-personal-info">
	<label for="firstname">Firstname:</label>
	<input type="text" name="firstname" id="firstname" placeholder="John" value="" size="50" maxlength="50">
	<label for="lastname">Lastname:</label>
    <input type="text" name="lastname" id="lastname" placeholder="Doe" value="" size="50" maxlength="50">
    <label for="email">Email address:</label>
    <input type="email" name="email" id="email" placeholder="john@doe.com" value="" size="50" maxlength="255">
    <label for="birthdate">Birthdate (optional):</label>
    <input type="text" name="birthdate" id="birthdate" placeholder="YYYY/MM/DD" value="" size="15" maxlength="10">
    <button type="submit">Submit</button>
</form>
```

We assume the following data is submitted to the application in the `$_POST` array:
 
```
Array (
	[firstname] => John
	[lastname]  => Doe
	[email]     => john@doe..com
	[birthdate] => 
)
```

A simple validation could look like this:

```php
<?php

$errors = [];

if ( empty($_POST['firstname']) )
{
	$errors[] = 'Please enter your firstname.';
}

if ( empty($_POST['lastname']) )
{
	$errors[] = 'Please enter your lastname.';
}

if ( empty($_POST['email']) )
{
	$errors[] = 'Please enter your email address.';
}
elseif ( !filter_var( $_POST['email'], FILTER_VALIDATE_EMAIL ) )
{
	$errors[] = 'The email address you entered is invalid.';
}

if ( !empty($_POST['birthdate']) )
{
	if ( !preg_match( "#^[0-9]{4}/[0-9]{2}/[0-9]{2}$#", $_POST['birthdate'] ) )
	{
		$errors[] = 'The birthdate you entered is invalid.';
	}
}

if ( !empty($errors) )
{
	print_r( $errors );
}
```

**Output:**

```
Array
(
    [0] => The email address you entered is invalid.
)
```
&nbsp;

#### What was done here?

* 33 lines of code were written to validate 4 values and evaluate the validation result.
* 6 if-elseif-branches were produced.
* The data validation was insufficient. For example a whitespace as firstname or the birthdate "9999/99/99" would be valid. 
* The same steps were repeated on variable data.

Obviously stating the valition more pecisely will produce a more complex and massive code.

This could lead to an amount of possible valid data combinations one person cannot be aware of. Although covering
such an "if-orgy" by 100% with unit tests would be hard.

---

### Refactoring #1: Validation methods

By establishing validation methods the code complexity can be reduced and the validation precision can be increased.
Every developer who had to validate a web form more than once, would have implemented a library of validation classes and methods
containing the precise checks for the different value types.
(I hope this is not just an educated guess.)
 
The following code assumes that there is a `Validator` class having several methods returning `FALSE` if their validation 
fails.

```php
<?php

$validator = new Validator();
$errors    = [];

if ( !$validator->checkName( $_POST['firstname'] ) )
{
	$errors[] = 'The firstname you entered is invalid.';
}

if ( !$validator->checkName( $_POST['lastname'] ) )
{
	$errors[] = 'The lastname you entered is invalid.';
}

if ( !validator->checkEmail( $_POST['email'] ) )
{
	$errors[] = 'The email address you entered is invalid.';
}

if ( !empty($_POST['birthdate']) )
{
	if ( !$validator->checkDate( $_POST['birthdate'] ) )
	{
		$errors[] = 'The birthdate you entered is invalid.';
	}
}

if ( !empty($errors) )
{
	print_r( $errors );
}
```

This results in code decreased by one if-branch, but with increased validation precision.
(If we assume that the `Validator` class checks more precisely than the code in the first example.)

The repetition of the same steps gets more clearly now, because we now only have if-statement pushing error messages
on an array, if they result TRUE.
 
Another pitfall is that every if-statement is negated, what harms the readability of the code.  

---

### Refactoring #2: Validation with the fluent interface

The [Fluent Interface](https://en.wikipedia.org/wiki/Fluent_interface) allows chaining object methods. 
This also works on validation methods.

Therfore the validation class has to overtake the responsability of holding/collecting the (boolean) result and - in case of failure - the error messages of each validation.

Let's give the previously shown `Validator` class a fluent interface.
For better comprehension we will name it `FluidValidator`. 
Furthermore we want to react positive on the repetitive negation by not asking "is not?", but asking "is?". 
So we rename the validation mehtods from `!check...` to `is...`.  

More about the topic why negation of if conditions is a bad habit, can be read in the book
[Clean Code by Robert C. Martin](http://www.amazon.de/gp/product/0132350882/ref=as_li_qf_sp_asin_il_tl?ie=UTF8&camp=1638&creative=6742&creativeASIN=0132350882&linkCode=as2&tag=phpindd-21). 

The validtion code could now look as follows:

```php
<?php

$fluidValidator = new FluidValidator();

$fluidValidator->isName( $_POST['firstname'], 'The firstname you entered is invalid.' )
	->isName( $_POST['lastname'], 'The lastname you entered is invalid.' )
	->isEmail( $_POST['email'], 'The email address you entered is invalid.' )
	->isDate( $_POST['birthdate'], 'Y/m/d', 'The birthdate you entered is invalid.' );
	
if ( $fluidValidator->failed() )
{
	print_r( $fluidValidator->getMessages() );
}
```

The code is significant shorter now, we have expressive "ask methods" and no negation anymore.
The birthdate indeed becomes a mandatory value in this variant, because the enclosing condition - if it is not empty - vanished.

To cover this use-case we could align a second variant to each validation method that processes validation only if the
given value is not empty. BUT, "empty" is not "empty", right? So let's consider our context - web forms and php. 
In this context there are at least two possibilities for "empty":
 
1. A parameter is not submitted at all, e.g. a checkbox was not checked.
2. A parameter has an empty value, e.g. an empty string. 

To avoid handling all the meanings of "empty" in the `FluidValidator` class, 
it makes sense to agree on ONE value that represents the meaning of "empty" and can be checked strictly.
`NULL` is the first value that seems to impose on us.
 
This difference should not be implemented by using an additional boolean parameter, because this would imply a method has two responsabilities.

To make clear the birthdate is optional there should be a variant of the `isDate` method, which does not check if the given value is `NULL`.
Let's name this method `isDateOrNull`.

Now the validation code would look like this:

```php
<?php

$fluidValidator = new FluidValidator();

$_POST['birthdate'] = $_POST['birthdate'] ?: NULL;

$fluidValidator->isName( $_POST['firstname'], 'The firstname you entered is invalid.' )
	->isName( $_POST['lastname'], 'The lastname you entered is invalid.' )
	->isEmail( $_POST['email'], 'The email address you entered is invalid.' )
	->isDateOrNull( $_POST['birthdate'], 'Y/m/d', 'The birthdate you entered is invalid.' );
	
if ( $validator->failed() )
{
	print_r( $validator->getMessages() );
}
```

To fulfill the agreement that empty values are `NULL`, `$_POST['birthdate']` needs to be re-assigned in our example, if an empty string was given.

We can face this problem by encapsulating the `$_POST` array into an object, like almost every mordern php framework does to avoid a distributed access on global variables.
This also prevents developers from unmotivated overrides of these global variables. That's why these objects are mostly implemented as __immutables__.

You can simply implement the rule for `NULL` values centrally in such a request object like this:

```php
<?php

class PostRequest
{
	/** @var array */
	private $postData;
	
	/**
	 * @param array $postData
	 */
	public function __construct( array $postData )
	{
		$this->postData = $postData;
	}
	
	/**
	 * @param string $key
	 *
	 * @return NULL|string|array
	 */
	public function getValue( $key )
	{
		if ( isset( $this->postData[$key]) )
		{
			return $this->postData[$key] ?: NULL;
		}
		
		return NULL;
	}
}
```

Now, let's bring the `PostRequest` object to our validation code:

```php
<?php

$postRequest    = new PostRequest( $_POST );
$fluidValidator = new FluidValidator();

$fluidValidator->isName( $postRequest->getValue('firstname'), 'The firstname you entered is invalid.' )
	->isName( $postRequest->getValue('lastname'), 'The lastname you entered is invalid.' )
	->isEmail( $postRequest->getValue('email'), 'The email address you entered is invalid.' )
	->isDateOrNull( $postRequest->getValue('birthdate'), 'Y/m/d', 'The birthdate you entered is invalid.' );
	
if ( $validator->failed() )
{
	print_r( $validator->getMessages() );
}
```

We found a better way to solve the "NULL problem", but we decreased the 
readablility and increased the redundance of our code again by repeatedly 
calling a getter on an object to supply validation values. 

As the `PostRequest` is already implemented as an immutable [Data Transfer Object (DTO)](https://en.wikipedia.org/wiki/Data_transfer_object)
it seems obvious to use this object as a data provider to the `FluidValidator`.

---

### Refactoring #3: Data provider for the FluidValidator 

For not beeing bound to the interface of the `PostRequest` object, the `FluidValidator` should provide an own interface for its data provider. Let's name this interface `ProvidesDataToValidate`.

The only responsibility of an object implementing this interface is to provide a value to validate for a given key. Thus there is only one expressive method in the interface:

```php
<?php

interface ProvidesDataToValidate
{
    /**
     * @param string key
     *
     * @return mixed
     */
	public function getValueToValidate( $key );
}
```

The name of the method should be chosen in a way that is not colliding with other common methods, e.g. `getValue()`.

Now let's implement the interface by the `PostRequest` class:

```php
<?php

class PostRequest implements ProvidesDataToValidate
{
	/** @var array */
	private $postData;
	
	/**
	 * @param array $postData
	 */
	public function __construct( array $postData )
	{
		$this->postData = $postData;
	}
	
	/**
	 * @param string $key
	 *
	 * @return NULL|string|array
	 */
	public function getValue( $key )
	{
		if ( isset( $this->postData[$key]) )
		{
			return $this->postData[$key];
		}
		
		return NULL;
	}
	
	/**
	 * @param string $key
	 *
	 * @return NULL|string|array
	 */
	public function getValueToValidate( $key )
	{
		return $this->getValue( $key ) ?: NULL;
	}
}
```

Additionally we now have the ability to apply our "NULL convention" only 
for cases of validation instead of applying it in general. So the return 
values of `getValue()` were left as is.

In the next step we inject the changed `PostReqeust` object into the `FluidValidator`'s constructor 
whos signature has changed as well and now allows an optional data provider:
 
```php
/**
 * @param ProvidesDataToValidate $dataValidator
 */
public function __construct( ProvidesDataToValidate $dataProvider = null )

```

Furthermore we changed the `FluidValidator` in a way that it now handles the given input values as keys to the data provider which provides the real values to validate. If there is no data provider given, the input values are treated as before - as values to validate.

Injecting the data provider results in the following validation code:

```php
<?php

// PostRequest now implements the ProvidesDataToValidate interface!

$postRequest    = new PostRequest( $_POST );
$fluidValidator = new FluidValidator( $postRequest );

$fluidValidator->isName( 'firstname', 'The firstname you entered is invalid.' )
	->isName( 'lastname', 'The lastname you entered is invalid.' )
	->isEmail( 'email', 'The email address you entered is invalid.' )
	->isDateOrNull( 'birthdate', 'Y/m/d', 'The birthdate you entered is invalid.' );
	
if ( $validator->failed() )
{
	print_r( $validator->getMessages() );
}
```

As you can see we now removed all redundant elements from our validation method calls.

### What do we have achieved so far?

* We reduced 33 lines of insufficient validation code to 10 lines and simultaneously increased the precision of the validation.
* We delegated the responsibilities of keeping data and data conformity to a class / interface (`PostRequest` / `ProvidesDataToValidate`).
* We delegated the precise validation of values to a class (`FluidValidator`) and produced a reusable library.
* We removed all avoidable redundancy from the validation code and are "[DRY](https://en.wikipedia.org/wiki/Don%27t_repeat_yourself) compliant".

Not bad, right!?

---

In the next chapter we want to respond to some use cases which often pop up when dealing with data validation.

### 1. Stop validation on first fail

There are use cases where you don't want all validation methods to be executed if a previous one failed.
One reason for that could be an expensive validation communicating with an external API or something like that.
In this case you only want to execute this validation when all the previous "cheap" checks have passed.

Adding this behaviour to the FluidValidator is really simple by introducing a check mode. 
Since the developer always has to decide in which mode the validator shall operate we'll place 
this descision before the optional data provider parameter in the `FluidValidator`'s constructor.

To stay open for further check modes we won't use a boolean flag. Instead we introduce expressive constants which are defined in a corresponding abstract class:

```php
<?php

abstract class CheckMode
{
	/** Execute ALL validation methods and collect ALL error messages (default) */
	const CONTINUOUS = 1;
	
	/** Do not execute any validation methods after one failed */
	const STOP_ON_FIRST_FAIL = 2;
}
```

So the signature of the `FluidValidator`'s constructor changes as follows:


```php
<?php
/**
 * @param int $checkMode (CheckMode::CONTINUOUS | CheckMode::STOP_ON_FIRST_FAIL)
 * @param ProvidesDataToValidate $dataProvider
 */
public function __construct( $checkMode = CheckMode::CONTINUOUS, ProvidesDataToValidate $dataProvider = null )
```

Validation code with stop on first fail:

```php
<?php

$postData = [
	'firstname' => 'John',
	'lastname'  => '',
	'email'     => 'john@doe..com',
	'birthdate' => '',
];

$postRequest    = new PostRequest( $postData );
$fluidValidator = new FluidValidator( CheckMode::STOP_ON_FIRST_FAIL, $postRequest );

$fluidValidator->isName( 'firstname', 'The firstname you entered is invalid.' )
	->isName( 'lastname', 'The lastname you entered is invalid.' )
	->isEmail( 'email', 'The email address you entered is invalid.' )
	->isDateOrNull( 'birthdate', 'Y/m/d', 'The birthdate you entered is invalid.' );
	
if ( $validator->failed() )
{
	print_r( $validator->getMessages() );
}
```

**Output:**

```
Array
(
    [0] => The lastname you entered is invalid.
)
```

The validation method checking the invalid email address was not executed.

### 2. Conditional validations

There are use cases where some validation methods shall be executed only if a previous condition is true.

#### 2.1 Validation of associated values

A classic example for this is the validation of a postal address. 
First all single elements of the address are checked for emptyness. 
Only if none of them is empty the validation for the whole data combination beeing a valid postal address shall took place.

Assuming the following data is posted to the application:

```
Array (
	[firstname] => John
	[lastname] => Doe
	[street] => Example Street
	[streetNumber] => 123d
	[zipCode] => 12345
	[city] => Exampletown
	[email] => john@doe..com
)
```

Syntactically this is a valid postal address, but semantically it is not.
Furthermore the given email address is not valid.

The requirements for the validation are:

* Check for valid first- and lastname.
* Check if every element of the postal address is not empty.
  * If so, check if the postal address really exists (or is semantically correct).
* Check for a valid email address.

That means a method is needed that checks whether the validation result is positive so far, or not. If so a number x of further validation methods shall be executed, or skipped. Let's name this method `ifPassed()` and give it a counter for executing/skipping a number of following methods depending on its check result.

The validation code with the `FluidValidator` could look as follows:

```php
<?php

$postRequest    = new PostRequest( $_POST );
$fluidValidator = new FluidValidator( CheckMode::CONTINUOUS, $postRequest );

$fluidValidator->isName( 'firstname', 'The firstname you entered is invalid.' )
	->isName( 'lastname', 'The lastname you entered is invalid.' )
	->isNonEmptyString( 'street', 'Please enter a street name.' )
	->isNonEmptyString( 'streetNumber', 'Please enter a street number.' )
	->isNonEmptyString( 'zipCode', 'Please enter a zipcode.' )
	->isNonEmptyString( 'city', 'Please enter a city.' )
	// Execute the following 1 validation method, if the validation result is positive so far,
	// otherwise skip the next 1 validation method.
	->ifPassed( 1 ) 
	->isPostalAddress( 'street', 'streetNumber', 'zipCode', 'city', 'This is not a valid postal address.' )
	->isEmail( 'email', 'The email address you entered is invalid.' );
	
if ( $validator->failed() )
{
	print_r( $validator->getMessages() );
}
```

**Output:**

```
Array (
	[0] => This is not a valid postal address.
	[1] => The email address you entered is invalid.
)
```

With the following data the expensive check for a valid postal address won't be executed:
(lastname is empty, email address is valid now)

```
Array (
	[firstname] => John
	[lastname] => 
	[street] => Example Street
	[streetNumber] => 123d
	[zipCode] => 12345
	[city] => Exampletown
	[email] => john@doe.com
)
```

---

#### 2.2 Validation depending on an input value
 
An example for this use case is the implicit question for a company name, when the user chose "Company" as the salutation.

Assuming the following data is posted to the application:

```
Array (
	[salutation] => Company
	[companyName] => 
	[firstname] => John
	[lastname] => Doe
)
```

So the validation code could look as follows:

```php
<?php

$postRequest    = new PostRequest( $_POST );
$fluidValidator = new FluidValidator( CheckMode::CONTINUOUS, $postRequest );

$fluidValidator->isOneStringOf( 'salutation', ['Mr.', 'Mrs.', 'Company'], 'The salutation is invalid.' )
	// If salutation == "Company", execute the following 1 validation method
	// If salutation != "Company", skip the following 1 validation method
    ->ifIsEqual( 'salutation', 'Company', 1 )
    ->isNonEmptyString( 'companyName', 'Please enter a company name.' )
	->isName( 'firtname', 'The firstname you entered is invalid.' )
	->isName( 'lastname', 'The lastname you entered is invalid.' );
	
if ( $validator->failed() )
{
	print_r( $validator->getMessages() );
}
```

**Output:**

```
Array (
	[0] => Please enter a company name.
)
```

It may be advisable to provide a conditional method for each validation method.

---

#### 2.3 Validate, if an external condition is true

Sometimes a validation of several values is only necessary if a condition is true that is not directly bounded to the input data, but to the surrounding programm code or the current state of the application.

A typical example for this is the difference of a newsletter subscription between a logged in user or a guest.
The email address of logged in users is already known and therefor must not be entered and validated.

Given the following data:

```
Array (
	[subscribe] => On
	[email] =>  
)
```

The validation code could look as follows:

```php
<?php

// This value would come from e.g. the session
$isGuest = TRUE;

$postRequest    = new PostRequest( $_POST );
$fluidValidator = new FluidValidator( CheckMode::CONTINUOUS, $postRequest );

$fluidValidator->isEqual( 'subscibe', 'On', 'Please confirm the newsletter subscription.' )
	// If $isGuest == TRUE (User is a guest), execute the following 1 validation method
	// If $isGuest == FALSE (User is logged in), skip the following 1 validation method
    ->checkIf( $isGuest, 1 )
    ->isEmail( 'email', 'The email address you entered is invalid.' );
	
if ( $validator->failed() )
{
	print_r( $validator->getMessages() );
}
```

**Hint:** The `checkIf()` method can be named `if()` since [php7](http://php.net/manual/en/migration70.other-changes.php#migration70.other-changes.loosening-reserved-words).

**Output:**

```
Array (
	[0] => The email address you entered is invalid.
)
```

### 3. Structured error messages

In the previous mentioned examples we get error messages from the `FluidValidator` as an one-dimensional array with numeric keys. In most cases this is not very useful for displaying these error messages, because you may want to print the messages at each associated input field or at a field group.

Let's stick to the previous example of the postal address.

* Is one of the address elements empty, the error shall be reportet at the corresponding input field.
* Are all address elements filled, but the address is invalid, the error message shall print below all the address input fields.

To achieve that it would be useful to have an assoc. array as the return value of
`FluidValidator->getMessages()`. But, as long as we cannot expect a data provider, so that keys are provided to the validation methods, we need to find another solution for structuring the error messages.

By the way provided keys to the validation methods wouldn't help that much anyway, because we'd bind every message directly to that key respectively to the associated input field, so a message for a group of input fields would not be possible.
 
Furthermore we actually don't want to force a fixed structure for the error messages. Every developer shall be capable of defining its own structure for its purpose.

An approach to solve this problem is to extend the `FluidValidator` with a collector 
object that has the responsability to collect produced error messages and return them in a user defined structure.

In the same way we added the data provider to the validator, we avoid to bind it to a precise class by just requiring an interface for such a collector object. Let's name this interface `CollectsMessages`.

This interface postulates the following requirements:

* Check every error message, if it was given in the correct type / format, so it is collectable in the required structure.
* Add an error message to the collection.
* Clear the collection.
* Return the structured collection.

The `CollectsMessages` interface could look as follows:

```php
<?php

interface CollectsMessages
{
	/**
	 * @param mixed $message
	 *
	 * @return bool
	 */
	public function isMessageValid( $message );
	
	/**
	 * @param mixed $message
	 */
	public function addMessage( $message );
	
	public function clearMessages();
	
	/**
	 * @return array
	 */
	public function getMessages();
}
```

**Important:** As we do not want to force any type or format for the error messages we won't use type hints in the methods `isMessageValid()` and `addMessage()`.

Now we change the `FluidValidator` constructor's signature by adding a third optional parameter:

```php
/**
 * @param int $checkMode (CheckMode::CONTINUOUS | CheckMode::STOP_ON_FIRST_FAIL)
 * @param ProvidesDataToValidate $dataProvider
 * @param CollectsMessages $messageCollector
 */
public function __construct( 
	$checkMode = CheckMode::CONTINUOUS, 
	ProvidesDataToValidate $dataProvider = null 
	CollectsMessages $messageCollector = null 
)
```

Furthermore we can assume the `FluidValidator` delegates the given error messages to the `$messageCollector` instance for checking (`$messageCollector->isMessageValid()`) before collecting (`$messageCollector->addMessage()`).
  
Additionally we establish the `FluidValidator->getMessages()` to be a 1:1 wrapper of the `$messageCollector->getMessages()` method.

The simplest implementation of the collector - the one that behaves the same as the `FluidValidator` did before - could look like this:

```php
<?php

class ScalarListMessageCollector implements CollectsMessages
{
	/** @var array */
	private $messages = [ ];
	
	/**
	 * @param string|int|float|bool $message
	 *
	 * @return bool
	 */
	public function isMessageValid( $message )
	{
		return is_scalar( $message );
	}
	
	/**
	 * @param string|int|float|bool $message
	 */
	public function addMessage( $message )
	{
		$this->messages[] = $message;
	}
	
	public function clearMessages()
	{
		$this->messages = [ ];
	}
	
	/**
	 * @return array
	 */
	public function getMessages()
	{
		return $this->messages;
	}
}
```

An implementation that fulfills our requirements from above for grouped error messages could look like this:

```php
<?php

class GroupedListMessageCollector implements CollectsMessages
{
	/** @var array */
	private $messages = [ ];
	
	/**
	 * @param mixed $message
	 *
	 * @return bool
	 */
	public function isMessageValid( $message )
	{
		# $message muss in der Form [ "key" => "message" ] übergeben werden
	
		if ( is_array( $message ) || ($message instanceof \Traversable) )
		{
			foreach ( $message as $key => $value )
			{
				if ( !is_scalar( $key ) || !is_scalar( $value ) )
				{
					return false;
				}
			}
			return true;
		}
		return false;
	}
	
	/**
	 * @param array $message
	 */
	public function addMessage( $message )
	{
		# Messages gruppiert nach Key sammeln
	
		foreach ( $message as $key => $value )
		{
			if ( isset($this->messages[ $key ]) )
			{
				$this->messages[ $key ] = array_merge( $this->messages[ $key ], [ $value ] );
			}
			else
			{
				$this->messages[ $key ] = [ $value ];
			}
		}
	}
	
	public function clearMessages()
	{
		$this->messages = [ ];
	}
	
	/**
	 * @return array
	 */
	public function getMessages()
	{
		return $this->messages;
	}
}
```

The validation code for our postal address data could now look as follows:  
(For the sake of simplicity we ignore the conditional checks here.)

```php
<?php

$postData = [
	['firstname'] 	 => 'John',
	['lastname'] 	 => 'Doe',
	['street'] 		 => 'Example Street',
	['streetNumber'] => '123d',
	['zipCode'] 	 => '12345',
	['city'] 		 => 'Exampletown',
	['email'] 		 => 'john@doe..com',
];

$postRequest      = new PostRequest( $postData );
$messageCollector = new GroupedListMessageCollector();
$fluidValidator   = new FluidValidator( CheckMode::CONTINUOUS, $postRequest, $messageCollector );

$fluidValidator->isName( 'firstname', ['firstname' => 'The firstname you entered is invalid.'] )
	->isName( 'lastname', ['lastname' => 'The lastname you entered is invalid.'] )
	->isNonEmptyString( 'street', ['street' => 'Please enter a street name.'] )
	->isNonEmptyString( 'streetNumber', ['streetNumber' => 'Please enter a street number.'] )
	->isNonEmptyString( 'zipCode', ['zipCode' => 'Please enter a zipcode.'] )
	->isNonEmptyString( 'city', ['city' => 'Please enter a city.'] )
	->isPostalAddress( 
		'street', 'streetNumber', 'zipCode', 'city', 
		['address' => 'This is not a valid postal address.'] 
	)
	->isEmail( 'email', ['email' => 'The email address you entered is invalid.'] );
	
if ( $validator->failed() )
{
	print_r( $validator->getMessages() );
}
```

**Output:**

```
Array (
	[address] => Array (
		[0] => This is not a valid postal address.
	)
	[email] => Array (
		[0] => The email address you entered is invalid.
	)
)
```

Et voilà, grouped error messages with keys that are not bound directly to input fields.

---

This is the end of the post and you may wonder how such a `FluidValidator` is implemented, do you?

The answer to this question can be found as a fully tested, well appointed implementation on my [GitHub repository](https://github.com/hollodotme/FluidValidator) and is available as a [composer package](https://packagist.org/packages/hollodotme/fluid-validator):

<div class="github-card" data-user="hollodotme" data-repo="FluidValidator" data-width="100%"></div>
<script src="//cdn.jsdelivr.net/github-cards/latest/widget.js"></script>

I am looking forward to your feedback and any contributions to the `FluidValidator`.

---

Related links / references:

* <i class="fa fa-github"></i> [hollodotme/FluidValidator](https://packagist.org/packages/hollodotme/fluid-validator)
* <i class="fa fa-wikipedia-w"></i> [Fluent Interface](https://en.wikipedia.org/wiki/Fluent_interface)
* <i class="fa fa-wikipedia-w"></i> [Data Transfer Objekt (DTO)](https://en.wikipedia.org/wiki/Data_transfer_object)
* <i class="fa fa-wikipedia-w"></i> [DRY - Don't Repeat Yourself](https://en.wikipedia.org/wiki/Don%27t_repeat_yourself)
* [php7 - loosing reserved word restrictions](http://php.net/manual/en/migration70.other-changes.php#migration70.other-changes.loosening-reserved-words)
