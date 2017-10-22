<?php declare(strict_types=1);

interface IdentifiesStream
{
	public function toString() : string;
}

interface IdentifiesStreamType
{
	public function toString() : string;
}

interface CountsVersion
{
	public function increment() : CountsVersion;

	public function toInt() : int;
}

interface NamesEvent
{
	public function toString() : string;
}

final class StreamSequence implements CountsVersion
{
	/** @var int */
	private $version;

	public function __construct( int $version )
	{
		$this->version = $version;
	}

	public function increment() : CountsVersion
	{
		return new self( $this->version + 1 );
	}

	public function toInt() : int
	{
		return $this->version;
	}
}

interface ProvidesEventMetaData
{
	public function getStreamType() : IdentifiesStreamType;

	public function getStreamId() : IdentifiesStream;

	public function getStreamSequence() : CountsVersion;
}

final class EventMetaData implements ProvidesEventMetaData
{
	/** @var \IdentifiesStreamType */
	private $streamType;

	/** @var \IdentifiesStream */
	private $streamId;

	/** @var \CountsVersion */
	private $streamSequence;

	public function __construct( \IdentifiesStreamType $streamType, \IdentifiesStream $streamId, \CountsVersion $streamSequence )
	{
		$this->streamType     = $streamType;
		$this->streamId       = $streamId;
		$this->streamSequence = $streamSequence;
	}

	public function getStreamType() : \IdentifiesStreamType
	{
		return $this->streamType;
	}

	public function getStreamId() : \IdentifiesStream
	{
		return $this->streamId;
	}

	public function getStreamSequence() : \CountsVersion
	{
		return $this->streamSequence;
	}
}

interface ProvidesEventPayload
{
	public function getName() : NamesEvent;
}

interface ProvidesEventFileInformation
{
	public function exists() : bool;

	public function getStream();
}

final class NullFileInfo implements ProvidesEventFileInformation
{
	public function exists() : bool
	{
		return false;
	}

	public function getStream()
	{
		throw new \LogicException( 'Null object cannot provide a file stream.' );
	}
}

final class FileInfo implements ProvidesEventFileInformation
{
	/** @var string */
	private $filePath;

	public function __construct( string $filePath )
	{
		$this->filePath = $filePath;
	}

	public function exists() : bool
	{
		return file_exists( $this->filePath );
	}

	public function getStream()
	{
		return fopen( $this->filePath, 'rb' );
	}
}

interface TiesEventInformation
{
	public function getMetaData() : ProvidesEventMetaData;

	public function getEvent() : ProvidesEventPayload;

	public function getFileInfo() : ProvidesEventFileInformation;
}

final class EventEnvelope implements TiesEventInformation
{
	/** @var \ProvidesEventMetaData */
	private $metaData;

	/** @var \ProvidesEventPayload */
	private $event;

	/** @var \ProvidesEventFileInformation */
	private $fileInfo;

	public function __construct( \ProvidesEventMetaData $metaData, \ProvidesEventPayload $event, \ProvidesEventFileInformation $fileInfo )
	{
		$this->metaData = $metaData;
		$this->event    = $event;
		$this->fileInfo = $fileInfo;
	}

	public function getMetaData() : \ProvidesEventMetaData
	{
		return $this->metaData;
	}

	public function getEvent() : \ProvidesEventPayload
	{
		return $this->event;
	}

	public function getFileInfo() : \ProvidesEventFileInformation
	{
		return $this->fileInfo;
	}
}

interface ListsChanges extends Iterator
{
	public function append( TiesEventInformation $eventEnvelope ) : void;
}

interface RecordsChanges
{
	public function getChanges() : ListsChanges;

	public function clearChanges() : void;
}

final class EventStream implements ListsChanges
{
	private $eventEnvelopes = [];

	public function current()
	{
		return current( $this->eventEnvelopes );
	}

	public function next() : void
	{
		next( $this->eventEnvelopes );
	}

	public function key()
	{
		return key( $this->eventEnvelopes );
	}

	public function valid() : bool
	{
		return null !== key( $this->eventEnvelopes );
	}

	public function rewind() : void
	{
		reset( $this->eventEnvelopes );
	}

	public function append( TiesEventInformation $eventEnvelope ) : void
	{
		$this->eventEnvelopes[] = $eventEnvelope;
	}
}

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

