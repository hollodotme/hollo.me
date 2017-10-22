## Use case

When I dove into event sourcing some years ago, I had a very challenging use-case for my first event-sourced project.
That time I was starting a new job after a sabbatical for a few month. This company needed a project management tool 
preferably "fulfilling all the unit's needs". One of these needs was a workflow for online shop frontend design-negotiation 
between the company's art directors (designer) and the customer's e-commerce (customer) team.

**A simplified version of this process is:**

1. Customer and designers work out a vision of a design and create a structured document / wishlist.  
2. Designer creates a first draft in some graphics app and produce a PDF to handover to the customer.
3. Customer reviews the design/PDF and adds remarks to that particular version of the design/PDF.
4. Designer reviews the comments on the design/PDF version and maybe adds own comments to that version.
5. Designer refines the design, produces a new PDF and uploads the new version  
   
   **_Repeating points 3 to 5 until the design is accepted._**  
   
7. Customer and designer agree on the final design by explicitly accepting a particular version.  


   They must not agree on the last version!

Obviously, in this case the content of the design PDFs is essential to understand comments from customer/designer and
the workout of the agreement, especially in a retrospective. The basic point was creating the ability to recap 
the whole refinement and decision process for both, customer and designer.

---

## Assumptions, discussion and decisions

I assumed that it is not needed to keep every version of a PDF available at the same time and that it is sufficient 
to get access to previous versions on demand.

I discussed about storing files in an event-sourced application in 
[this google group](https://groups.google.com/forum/#!topic/dddinphp/5DYL9T9vwmU) with a couple of people.
Please keep in mind that this discussion was about 3 years ago, that I was not as educated and open-minded as I am today 
and some parts of the discussion may be incorrect, ignorant or event offending from a current point of view.

Based on the use-case I decided to model an own aggregate for attachments, to keep track of its state (acceptance) 
, its version and comments attached to each version.

I decided to see the actual file the customer/designer will see to be a projection and the file system it is stored in to 
be a projection infrastructure.

I also decided to store the binary data of the PDF version beside the actual event store table in another table containing 
a BLOB column and an ID column, which is a foreign key to the actual event store table. 

---

## Big picture

[![Event Sourcing: Large file projection big picture](@baseUrl@/img/posts/es-large-file-projection-big-picture.svg)](@baseUrl@/img/posts/es-large-file-projection-big-picture.svg)

_Figure 1: Large file projection big picture_

**Please note:** Some parts of this illustration are simplified for better comprehension. For example the IDs in the 
event store and file store tables are not auto-increment integers as this picture implies.

_Figure 1_ shows that the _Attachment aggregate_ emits several events with or without a file attached.

Events with a file attached:

* **Attachment uploaded**
* **New attachment version uploaded**

Events without a file attached:

* **Attachment name was changed**
* **Attachment commented**
* **Attachment version accepted**

---

## Consequences in implementation

Due to the fact that events with a file attached need a special treatment when it comes to persistence, there must be
a general indicator if a file is attached to an event or not. So I decided to add another peace of information to the 
class that wraps and passes around my events - the event envelope.

The following interface describes the event envelope:

<a name="event-envelope-interface"></a>
```php
<?php declare(strict_types=1);

interface TiesEventInformation
{
	public function getMetaData() : ProvidesEventMetaData;
	
	public function getEvent() : ProvidesEventPayload;
	
	public function getFileInformation() : ProvidesEventFileInformation;
}
```
_Snippet 1: Event envelope interface_

The event envelope is created in the `recordThat()` method of the aggregate class.

For a clearer picture how my abstract aggregate class looks like, see the following code and comments:

<div class="text-small text-right">
    <a href="#abstract-aggregate-root" data-toggle=".language-php .token.comment">Toggle inline comments</a>
</div>
<a name="abstract-aggregate-root"></a>

```php
<?php declare(strict_types=1);

abstract class AbstractAggregateRoot implements RecordsChanges
{
	/** @var \ListsChanges */
	private $changes;

	/** @var \CountsVersion */
	private $sequence;

	/**
	 * Constructor is protected to prevent instantiation of this class without an event stream.
	 */
	protected function __construct()
	{
		/**
		 * $this->changes is a list of event envelopes in chronological order
		 * representing all state changes that happened to this object since instantiation.
		 * EventStream implements the ListsChanges interface, which extends Iterator interface
		 */
		$this->changes = new EventStream();

		/**
		 * The sequence is the internal version number of this particular aggregate instance.
		 * It is incremented by each change that is recorded and will be used to sort the events
		 * of this stream inside the event store.
		 */
		$this->sequence = new StreamSequence( 0 );
	}

	/**
	 * Returns an identifier for the current stream type.
	 * In this example it would be StreamType( 'Attachment' )
	 */
	abstract public function getStreamType() : IdentifiesStreamType;

	/**
	 * Returns an identifier for the current aggregate instance resp. stream.
	 * This is usually a UUID, e.g. new AttachmentId( 'c1053f8f-c488-4cc8-954c-6eef51e749ac' )
	 */
	abstract public function getStreamId() : IdentifiesStream;

	/**
	 * Internal method to record all changes resp. events.
	 * Optionally an attached file can be recorded, represented by a file path.
	 *
	 * @param ProvidesEventPayload $event    Data object containing the actual change payload
	 * @param string|null          $filePath Possibly attached file represented as file path
	 *
	 * @throws \LogicException if method to apply event is not callable
	 */
	final protected function recordThat( ProvidesEventPayload $event, ?string $filePath = null ) : void
	{
		/**
		 * $metaData ties everything together to unique identify the change / event.
		 * This class implements the ProvidesEventMetaData interface
		 *
		 * Please note: The sequence is not incremented here,
		 * but an incremented value is passed to the class.
		 * The increase of the sequence is done in the apply() method below,
		 * based on the currently applied change.
		 */
		$metaData = new EventMetaData(
			$this->getStreamType(),
			$this->getStreamId(),
			$this->sequence->increment()
		);

		/**
		 * $fileInfo provides information about a possibly attached file based on the given $filePath.
		 * If there is no file path given a null object is used, otherwise a FileInfo object.
		 * Both implement the ProvidesEventFileInformation interface.
		 */
		$fileInfo = (null === $filePath) ? new NullFileInfo() : new FileInfo( $filePath );

		/**
		 * $eventEnvelope ties all event information together for passing around
		 * only one object between application, persistence and projection infrastructure.
		 */
		$eventEnvelope = new EventEnvelope( $metaData, $event, $fileInfo );

		/**
		 * $eventEnvelope represents a newly made change to the state of
		 * this instance and is therefor added to the event stream.
		 */
		$this->changes->append( $eventEnvelope );

		/**
		 * At last the newly recorded change needs to be applied to this instance.
		 */
		$this->apply( $eventEnvelope );
	}

	/**
	 * Internal method to apply a recorded change to the current instance
	 * by calling a when* method which actually changes the internal state of the instance.
	 *
	 * @param TiesEventInformation $eventEnvelope Event envelope representing a recorded change
	 *
	 * @throws \LogicException if method to apply event is not callable
	 */
	private function apply( TiesEventInformation $eventEnvelope ) : void
	{
		/**
		 * Determining the actual when* method to apply the current change
		 */
		$classNameParts = explode( '\\', get_class( $eventEnvelope->getEvent() ) );
		$methodName     = sprintf( 'when%s', preg_replace( '#Event$#', '', end( $classNameParts ) ) );

		if ( !is_callable( [$this, $methodName] ) )
		{
			throw new \LogicException(
				sprintf(
					'Method to apply event "%s" (%s) not callable.',
					$eventEnvelope->getEvent()->getName()->toString(),
					$methodName
				)
			);
		}

		/**
		 * Calling the when* method to process the actual state change.
		 */
		$this->$methodName( $eventEnvelope->getEvent() );

		/**
		 * Increase of the stream sequence based on the current change that was applied
		 */
		$this->sequence = $eventEnvelope->getMetaData()->getStreamSequence();
	}

	/**
	 * Generic static method to rebuild an aggregate instance based on
	 * a list of events in chronological order.
	 * Each change is applied to a newly created instance of this aggregate.
	 *
	 * @param ListsChanges $changes List of event envelopes
	 *
	 * @throws \LogicException if method to apply event is not callable
	 *
	 * @return static
	 */
	final public static function reconstituteFromHistory( ListsChanges $changes )
	{
		$aggregate = new static();

		foreach ( $changes as $eventEnvelope )
		{
			$aggregate->apply( $eventEnvelope );
		}

		return $aggregate;
	}
}
```

_Snippet 2: Abstract aggregate root_ 
 | <small><a href="#abstract-aggregate-root" data-toggle=".language-php .token.comment">Toggle inline comments</a></small>

