---
layout: posts
title: Custom exceptions with context
tags: [traits, partial class, config, PHP, code]
permalink: /php/custom-exceptions-with-context.html
slug: php
---

## The problem

While our web applications grow, the developers concentrate on new features and business logic, 
the topic of error handling is often neglected. 

Quite often this results in wild changes to method return values or - even worst - passing additional parameters by reference
to bring uprising errors to the presentation layer.

Let's take a typical example: Changing a customer's email address with the following subtasks:

1. Load a `Customer` object by a customer ID from storage 
2. Change the email address of that `Customer` object
3. Unload the `Customer` object to the storage
4. Handling a request that invokes the previous 3 tasks

The author of the following code chose "wisely" an `ErrorList` object instead of a php array for collecting error messages.

### Load the Customer object from storage

```php
<?php

class CustomerRepository 
{
	/**
	 * @param string $customerId
	 *
	 * @return Customer|ErrorList
	 */
	public function findOneWithId( string $customerId )
	{
		// Code that loads the customer object from storage
		 	
		if ( $customer !== null )
		{
			return $customer;
		}
		
		return new ErrorList( "Customer with ID {$customerId} not found." );
	}
}
```

This code already comes up with a bunch of problems:

* The method has different return types, so you can't define a return type hint in php7
* The method has more than one responsibility, namely
  * returning the customer object we're searching for 
  * creating an error message for the user
* The context of the error is ambiguous for further handling, or is encapsulated in the plaintext error message. 

But let's go on for now ...

### Change the customer object's email address

```php
<?php

class Customer
{
	private $email;

	/**
	 * @param string $newEmail
	 *
	 * @return ErrorList
	 */
	public function changeEmail( string $newEmail ) : ErrorList
	{
		$errorList = new ErrorList();
		
		if ( empty($newEmail) )
		{
			$errorList->addMessage( 'Email address is empty.' ); 
		}
		elseif ( !filter_var( $newEmail, FILTER_VALIDATE_EMAIL ) )
		{
			$errorList->addMessage( 'Email address is invalid.' );
		}
		elseif ( $newEmail == $this->email )
		{
			$errorList->addMessage( 'Email address is same as current address.' );
		}
		
		$this->email = $newEmail;
		
		return $errorList;
	}
}
```
  
---
 
### Unload the Customer object to the storage

```php
<?php

class CustomerRepository 
{
	/**
	 * @param Customer $customer
	 *
	 * @return ErrorList
	 */
	public function update( Customer $customer ) : ErrorList
	{
		$errorList = new ErrorList();
	
		// Code that unloads the customer object to the storage
		 	
		if ( !$updateResult )
		{
			$errorList->addMessage( "Updating customer object failed." );
		}
		
		return $errorList;
	}
}
```

---

### Handling the request to change a customer's email address

```php
<?php

class ChangeCustomerEmailHandler
{
	/**
	 * @param Request $request
	 */
	public function handle( Request $request )
	{
		$errorList = new ErrorList();
		
		$customerRepository = new CustomerRepository();
		
		# 1. Load the customer object from storage
		$customer = $customerRepository->findOneWithId( $request->getCustomerId() );
		
		if ( $customer instanceof Customer )
		{
			# 2. Change the customer object's email address
			$error = $customer->changeEmail( $request->getNewEmail() );
			
			if ( !$error->isEmpty() )
			{
				$errorList->append( $error );
			}
			else
			{
				# 3. Unload changed customer object to storage
				$error = $customerRepository->update( $customer );
				
				if ( !$error->isEmpty() )
				{
					$errorList->append( $error );
				}
			}
		}
		elseif ( $customer instance of ErrorList )
		{
			$errorList->append($customer);
		}
		
		if ( $errorList->isEmpty() )
		{
			echo "Email address was changed successfully.";
		}
		else
		{
			echo "Email address could not be changed: ";
			echo join( '<br>', $errorList->getMessages() );
		}
	}
}
```

Code like this is

* hardly untestable, because you have no mechanical information about what error occured or where,
* a bad read, because e.g. `$customer` has two completely different value types assigned (`Customer` object or `ErrorList` object),
* too nested, because you must react on so many method behaviours and
* not reusable without duplicating all the nested handling of the method's return types.

By the way you can ask yourself, if you really want to present a message to the user, if your system was unable to persist a customer. 

Another problem with these specific, distributed error messages for users is, you can't change them for other use cases 
or languages without turning the whole application upside-down.

---

## Refactoring #1: Exceptions instead of ErrorList

In the first step of our refactoring we'll replace the `ErrorList` returns with `Exceptions`.

```php
<?php

class CustomerRepository 
{
	/**
	 * @param string $customerId
	 *
	 * @throws Exception
	 * @return Customer
	 */
	public function findOneWithId( string $customerId ) : Customer
	{
		// Code that loads the customer object from storage
		 	
		if ( $customer !== null )
		{
			return $customer;
		}
		
		throw new Exception( "Customer with ID {$customerId} not found." );
	}
	
	/**
	 * @param Customer $customer
	 *
	 * @throws Exception
	 */
	public function update( Customer $customer )
	{
		// Code that unloads the customer object to storage
			
		if ( !$updateResult )
		{
			throw new Exception( "Updating customer object failed." );
		}
	}
}

class Customer
{
	private $email;

	/**
	 * @param string $email
	 *
	 * @throws Exception
	 */
	public function changeEmail( string $newEmail )
	{
		if ( empty($newEmail) )
		{
			throw new Exception( 'Email address is empty.' ); 
		}
		elseif ( !filter_var( $newEmail, FILTER_VALIDATE_EMAIL ) )
		{
			throw new Exception( 'Email address is invalid.' );
		}
		elseif ( $newEmail == $this->email )
		{
			throw new Exception( 'Email address is same as current address.' );
		}
		
		$this->email = $newEmail;
	}
}

class ChangeCustomerEmailHandler
{
	/**
	 * @param Request $request
	 */
	public function handle( Request $request )
	{
		$customerRepository = new CustomerRepository();
		
		try 
		{
			# 1. Loading the customer object from storage
			$customer = $customerRepository->findOneWithId( $request->getCustomerId() );
		
			# 2. Changing the customer object's email address
			$customer->changeEmail( $request->getNewEmail() );
			
			# 3. Unloading the customer object to storage
			$customerRepository->update( $customer );
			
			echo "Email address was changed successfully.";
		}
		catch ( Exception $e )
		{
			echo "Email address could not be changed: " . $e->getMessage();
		}
	}
}
```

By this first change we can see at a glance:

* the code is notably shorter, especially in the `ChangeCustomerEmailHandler`,
* the code is more comprehensible, because every variable has a clear value assigned,
* the code is technically more precise, because we could give `CustomerRepository::findOneWithId` a unique return type and
* the code is less nested.

But the following problems remain unresolved:

* We can not identify the origin context of error messages.
* The `Exception`'s messages still contain error messages for users.

---

## Refactoring #2: Context exceptions

To get aware of the context the error occured in we'll replace the generic `Exception` class with our own _Context Exceptions_.
To do so, we need to identify the contexts in the first place:

* 1st context is the scope of the `CustomerRepository` class to load and unload `Customer` objects from/to the storage. Let's name this context `CustomerStorage`.
* 2nd context is the scope of the `Customer` class to change its state by changing its data. Let's name this context `CustomerData`.

Now we can deduce the names of our exceptions from these contexts:

1. `CustomerStorageException`
2. `CustomerDataException`

Depending on your application or structure of components you can define contexts more widely.
The macro context here would be `Customer` and its _Context Exception_ `CustomerException`.

But let's move on with our two contexts. 

After the replacement the code looks as follows:

```php
<?php

class CustomerStorageException extends Exception {}
class CustomerDataException extends Exception {}

class CustomerRepository 
{
	/**
	 * @param string $customerId
	 *
	 * @throws Exception
	 * @return Customer
	 */
	public function findOneWithId( string $customerId ) : Customer
	{
		// Code that loads the customer object from storage
		 	
		if ( $customer !== null )
		{
			return $customer;
		}
		
		throw new CustomerStorageException( "Customer with ID {$customerId} not found." );
	}
	
	/**
	 * @param Customer $customer
	 *
	 * @throws Exception
	 */
	public function update( Customer $customer )
	{
		// Code that unloads the customer object to storage
			
		if ( !$updateResult )
		{
			throw new CustomerStorageException( "Updating customer object failed." );
		}
	}
}

class Customer
{
	private $email;

	/**
	 * @param string $email
	 *
	 * @throws Exception
	 */
	public function changeEmail( string $newEmail )
	{
		if ( empty($newEmail) )
		{
			throw new CustomerDataException( 'Email address is empty.' ); 
		}
		elseif ( !filter_var( $newEmail, FILTER_VALIDATE_EMAIL ) )
		{
			throw new CustomerDataException( 'Email address is invalid.' );
		}
		elseif ( $newEmail == $this->email )
		{
			throw new CustomerDataException( 'Email address is same as current address.' );
		}
		
		$this->email = $newEmail;
	}
}

class ChangeCustomerEmailHandler
{
	/**
	 * @param Request $request
	 */
	public function handle( Request $request )
	{
		$customerRepository = new CustomerRepository();
		
		try 
		{
			# 1. Loading the customer object from storage
			$customer = $customerRepository->findOneWithId( $request->getCustomerId() );
		
			# 2. Changing the customer object's email address
			$customer->changeEmail( $request->getNewEmail() );
			
			# 3. Unloading the customer object to storage
			$customerRepository->update( $customer );
			
			echo "Email address was changed successfully.";
		}
		catch ( CustomerDataException $e )
		{
			echo "Emailaddress could not be changed: " . $e->getMessage();
		}
		catch ( CustomerStorageException $e )
		{
			echo "Storage error.";
		}
	}
}
```

By adding a second `catch` branch we now can differentiate between both error contexts.
This allows us to handle these errors in a more compliant way.
 
* On errors from the `CustomerData` context a message shall be presented to the user.
* On errors from the `CustomerStorage` context the admin shall be informed and the user shall get an "Internal Server Error" response.
 
This is quite easy now:

```php
<php

class ChangeCustomerEmailHandler
{
	/**
	 * @param Request $request
	 */
	public function handle( Request $request )
	{
		$customerRepository = new CustomerRepository();
		
		try 
		{
			# 1. Loading the customer object from storage
			$customer = $customerRepository->findOneWithId( $request->getCustomerId() );
		
			# 2. Changing the customer object's email address
			$customer->changeEmail( $request->getNewEmail() );
			
			# 3. Unloading the customer object to storage
			$customerRepository->update( $customer );
			
			echo "Email address was changed successfully.";
		}
		catch ( CustomerDataException $e )
		{
			# Message to the user
			echo "Email address could not be changed: " . $e->getMessage();
		}
		catch ( CustomerStorageException $e )
		{
			# Inform the admin
			mail( 'admin@example.com', 'Customer storage error', $e->getMessage() );
			
			# Internal Server Error response to user
			header( 'Content-Type: text/plain', true, 500 );
			echo "Internal Server Error";
		}
	}
}
```

In most cases this context distiction is too rough to present proper messages to the user or even offer adequate actions.
 
So it makes sense to bring more precision into our introduced contexts by extending the exceptions to more specialized ones.
 
By the way we still need to solve the problem of distributed user messages in several application layers.
 
There are 2 concrete errors in the `CustomerStorage` context:

1. No customer was found for the given customer ID.
2. Updating the customer object in storage failed.

There are 3 concrete errors in the `CustomerData` context:

1. The new email address is empty.
2. The new email address is invalid.
3. The new email address is the same as the current one.

We can merge the first two errors together, because an empty email address is an invalid email address.

For a better read of our code we choose expressive names for our new exceptions:

```php
<?php

# CustomerStorage context

class CustomerNotFound extends CustomerStorageException {}

class UpdatingCustomerFailed extends CustomerStorageException {}

# CustomerData context

class InvalidEmailAddress extends CustomerDataException {}

class EmailAddressAlreadySet extends CustomerDataException {}
```

... and embed them into our code:

```php
<?php

class CustomerRepository 
{
	/**
	 * @param string $customerId
	 *
	 * @throws CustomerNotFound
	 * @return Customer
	 */
	public function findOneWithId( string $customerId ) : Customer
	{
		// Code that loads the customer object from storage
		
		$this->guardCustomerWasFound( $customer );

		return $customer;
	}
	
	/**
	 * @param Customer|null $customer
	 *
	 * @throws CustomerNotFound
	 */
	private function guardCustomerWasFound( $customer )
	{
		if ( !($customer instanceof Customer) )
		{
			throw new CustomerNotFound();
		}
	}
	
	/**
	 * @param Customer $customer
	 *
	 * @throws UpdatingCustomerFailed
	 */
	public function update( Customer $customer )
	{
		// Code that unloads the customer object to storage
			
		$this->guardCustomerWasUpdated( $updateResult );
	}
	
	/**
	 * @param bool $updateResult
	 *
	 * @throws UpdatingCustomerFailed
	 */
	private function guardCustomerWasUpdated( bool $updateResult )
	{
		if ( $updateResult === false )
		{
			throw new UpdatingCustomerFailed();
		}
	}
}

class Customer
{
	private $email;

	/**
	 * @param string $email
	 *
	 * @throws Exception
	 */
	public function changeEmail( string $newEmail )
	{
		$this->guardEmailAddressIsValid( $newEmail );
		$this->guardEmailAddressDiffers( $newEmail );
		
		$this->email = $newEmail;
	}
	
	/**
	 * @param string $email
	 *
	 * @throws InvalidEmailAddress
	 */
	private function guardEmailAddressIsValid( string $email )
	{
		if ( empty($newEmail) )
		{
			throw new InvalidEmailAddress(); 
		}
		
		if ( !filter_var( $newEmail, FILTER_VALIDATE_EMAIL ) )
		{
			throw new InvalidEmailAddress();
		}
	}
	
	/**
	 * @param string
	 *
	 * @throws EmailAddressAlreadySet
	 */
	private function guardEmailAddressDiffers( string $email )
	{
		if ( $newEmail == $this->email )
		{
			throw new EmailAddressAlreadySet();
		}
	}
}

class ChangeCustomerEmailHandler
{
	/**
	 * @param Request $request
	 */
	public function handle( Request $request )
	{
		$customerRepository = new CustomerRepository();
		
		try 
		{
			# 1. Loading customer object from storage
			$customer = $customerRepository->findOneWithId( $request->getCustomerId() );
		
			# 2. Changing the customer object's email address
			$customer->changeEmail( $request->getNewEmail() );
			
			# 3. Unloading the customer object to storage
			$customerRepository->update( $customer );
			
			echo "Email address was changed successfully.";
		}
		catch ( InvalidEmailAddress $e )
		{
			# Message to the user
			echo "The given email address is invalid. Please check your input.";
		}
		catch ( EmailAddressAlreadySet $e )
		{
			# Message to the user
            echo "Please choose another email address.";
		}
		catch ( CustomerStorageException $e )
		{
			# Inform admin
			mail( 'admin@example.com', 'Customer storage error', get_class( $e ) . ' - ' . $e->getMessage() );
			
			# Internal Server Error response to the user
			header( 'Content-Type: text/plain', true, 500 );
			echo "Internal Server Error";
		}
	}
}
```

For better separation of concerns checks were extracted to `guard...()` methods.
This also reduces the complexity of our business methods.

By adding another `catch` branch we now can differentiate between both concrete errors from the `CustomerData` context.
Errors from `CustomerStorage` context remain grouped by a single `catch`, but we now tell the admin what error occurred (`get_class( $e )`).

This means we're free to decide whether to handle occurred errors precisely or in less detail.

Furthermore we do not have user messages in the `CustomerRepository` and `Customer` class anymore. 
These user messages are genereted in the `ChangeCustomerEmailHandler` according to requirements. 
So now we have a closed context where we can produce user or language specific error messages. 
And this is where they belong, the place nearest to or even part of the presentation layer.

In addition our code is reusable now, because another handler can react on our _Context Exceptions_ in a different way, 
without us changing the code the errors come from.

Let's summarize:

* We gave context to our error messages by creating _Context Exceptions_ and throwing them in their context.
* We can differentiate errors by their context.
* We specialized our _Context Exceptions_ to increase their information content.
* We separated the concerns of our methods.
* We moved user messages to where the output is generated / prepared.
* We made our business code reusable.

---

## Refactoring #3: Even more context, please.

In the beginning our code included the information which customer ID we searched for, when the error occured that it could not be found.

```php
return new ErrorList( "Customer with ID {$customerId} not found." );
```

This information vanished by our replacement with Exceptions.

```php
throw new CustomerNotFound();
```

Poor admin, who now gets the email with this error message. He won't be able to search any backup for a distinct customer ID.  

In order to get additional information from the specific context to our error description, we simply can extend our 
specialized _Context Exceptions_. A best practice is to use so called `with...()` methods, because they ensure a good read of the code.
 
On the example of our `CustomerNotFound` exception this looks like this:
 
```php
<?php

class CustomerNotFound extends CustomerStorageException
{
	private $customerId = '';
	
	public function withCustomerId( string $customerId ) : self
	{
		$this->customerId = $customerId;
		
		return $this;
	}
	
	public function getCustomerId() : string
	{
		return $this->customerId;
	}
}
```

So its call will change as follows:

```php
<?php

class CustomerRepository 
{
	/**
	 * @param string $customerId
	 *
	 * @throws CustomerNotFound
	 * @return Customer
	 */
	public function findOneWithId( string $customerId ) : Customer
	{
		// Code that loads the customer object from storage
		
		$this->guardCustomerWasFound( $customerId, $customer );

		return $customer;
	}
	
	/**
	 * @param string $customerId
	 * @param Customer|null $customer
	 *
	 * @throws CustomerNotFound
	 */
	private function guardCustomerWasFound( string $customerId, $customer )
	{
		if ( !($customer instanceof Customer) )
		{
			throw (new CustomerNotFound)->withCustomerId( $customerId );
		}
	}
}
```

And handling this error could possibly look like this:

```php
<?php

class ChangeCustomerEmailHandler
{
	/**
	 * @param Request $request
	 */
	public function handle( Request $request )
	{
		$customerRepository = new CustomerRepository();
		
		try 
		{
			# 1. Loading the customer object from storage
			$customer = $customerRepository->findOneWithId( $request->getCustomerId() );
		
			# ...
			
			echo "Email address was changed successfully.";
		}
		/*
		
		...
		
		*/
		catch ( CustomerNotFound $e )
		{
			# Inform admin
			$subject = 'Customer with ID ' . $e->getCustomerId() . ' not found in storage.';
			mail( 'admin@example.com', $subject, get_class( $e ) . ' - ' . $e->getMessage() );
			
			# Internal Server Error response to customer
			header( 'Content-Type: text/plain', true, 500 );
			echo "Internal Server Error";
		}
	}
}
```

As we handle the precise `CustomerNotFound` exception we make sure there is a getter for a customer ID providing 
information we can embed in the error message.

Of course this use case is trivial, because we could have taken the customer ID from the `Request` object.
More interesting than the customer ID would be e.g. the current used storage.

With this aproach we now are able to "carry" usually hidden information from the context to the error handling part of our application.

\- HaPHPy throwing!

<small>01/16/2016</small>
