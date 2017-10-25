<?php declare(strict_types=1);

abstract class StringType
{
	/** @var string */
	private $value;

	public function __construct( string $value )
	{
		$this->value = $value;
	}

	public function toString() : string
	{
		return $this->value;
	}
}

abstract class IntType
{
	/** @var int */
	private $value;

	public function __construct( int $value )
	{
		$this->value = $value;
	}

	public function toInt() : int
	{
		return $this->value;
	}
}

abstract class ArrayType
{
	/** @var array */
	private $values;

	public function __construct( array $values )
	{
		$this->values = $values;
	}

	public function toArray() : array
	{
		return $this->values;
	}
}

interface IdentifiesStream
{
	public function toString() : string;
}

final class AttachmentId extends StringType implements IdentifiesStream
{
	public static function generate() : self
	{
		return new self( bin2hex( random_bytes( 16 ) ) );
	}
}

interface IdentifiesStreamType
{
	public function toString() : string;
}

final class AttachmentStreamType implements IdentifiesStreamType
{
	private const STREAM_TYPE = 'attachment';

	public function toString() : string
	{
		return self::STREAM_TYPE;
	}
}

interface CountsVersion
{
	public function increment() : CountsVersion;

	public function toInt() : int;
}

final class StreamSequence extends IntType implements CountsVersion
{
	public function increment() : CountsVersion
	{
		return new self( $this->toInt() + 1 );
	}
}

interface NamesEvent
{
	public function toString() : string;
}

final class EventName extends StringType implements NamesEvent
{
	public static function fromClassName( string $className ) : self
	{
		$parts     = explode( '\\', $className );
		$baseName  = preg_replace( '#Event$#', '', end( $parts ) );
		$eventName = ltrim( preg_replace( '#([A-Z]+)([a-z]+)#', ' $1$2', $baseName ) );

		return new self( $eventName );
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

final class EventPayload extends ArrayType
{
}

interface ProvidesEventPayload
{
	public function getStreamId() : IdentifiesStream;

	public function getName() : NamesEvent;

	public function getPayload() : EventPayload;

	public static function newFromPayload( EventPayload $payload ) : ProvidesEventPayload;
}

abstract class AbstractEvent implements ProvidesEventPayload
{
	public function getPayload() : EventPayload
	{
		return new EventPayload( $this->toArray() );
	}

	abstract protected function toArray() : array;

	public static function newFromPayload( EventPayload $payload ) : ProvidesEventPayload
	{
		/** @var \AbstractEvent $event */
		$event = (new \ReflectionClass( static::class ))->newInstanceWithoutConstructor();
		$event->fromArray( $payload->toArray() );

		return $event;
	}

	abstract protected function fromArray( array $payload ) : void;

	public function getName() : NamesEvent
	{
		return EventName::fromClassName( static::class );
	}
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

	public function getStream() : void
	{
		throw new \LogicException( 'Null object cannot provide a file stream.' );
	}
}

final class FileInfo implements ProvidesEventFileInformation
{
	/** @var \FilePath */
	private $filePath;

	public function __construct( FilePath $filePath )
	{
		$this->filePath = $filePath;
	}

	public function exists() : bool
	{
		return file_exists( $this->filePath->toString() );
	}

	public function getStream()
	{
		return fopen( $this->filePath->toString(), 'rb' );
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

interface RecordsChanges
{
	public function getChanges() : ListsChanges;

	public function clearChanges() : void;
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
		$this->clearChanges();

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
	 * @param FilePath|null        $filePath Possibly attached file represented as file path
	 *
	 * @throws \LogicException if method to apply event is not callable
	 */
	final protected function recordThat( ProvidesEventPayload $event, ?FilePath $filePath = null ) : void
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
			$event->getStreamId(),
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

	final public function getChanges() : ListsChanges
	{
		return $this->changes;
	}

	final public function clearChanges() : void
	{
		$this->changes = new EventStream();
	}
}

final class FileName extends StringType
{
}

final class FilePath extends StringType
{
}

final class MimeType extends StringType
{

}

final class FileSize extends IntType
{

}

final class AttachmentUploadedEvent extends AbstractEvent
{
	/** @var \AttachmentId */
	private $attachmentId;

	/** @var \FileName */
	private $fileName;

	/** @var \MimeType */
	private $mimeType;

	/** @var \FileSize */
	private $fileSize;

	public function __construct( \AttachmentId $attachmentId, \FileName $fileName, \MimeType $mimeType, \FileSize $fileSize )
	{
		$this->attachmentId = $attachmentId;
		$this->fileName     = $fileName;
		$this->mimeType     = $mimeType;
		$this->fileSize     = $fileSize;
	}

	public function getAttachmentId() : \AttachmentId
	{
		return $this->attachmentId;
	}

	public function getFileName() : \FileName
	{
		return $this->fileName;
	}

	public function getMimeType() : \MimeType
	{
		return $this->mimeType;
	}

	public function getFileSize() : \FileSize
	{
		return $this->fileSize;
	}

	public function getStreamId() : IdentifiesStream
	{
		return $this->attachmentId;
	}

	protected function toArray() : array
	{
		return [
			'attachmentId' => $this->attachmentId->toString(),
			'fileName'     => $this->fileName->toString(),
			'mimeType'     => $this->mimeType->toString(),
			'fileSize'     => $this->fileSize->toInt(),
		];
	}

	protected function fromArray( array $payload ) : void
	{
		$this->attachmentId = new AttachmentId( $payload['attachmentId'] );
		$this->fileName     = new FileName( $payload['fileName'] );
		$this->mimeType     = new MimeType( $payload['mimeType'] );
		$this->fileSize     = new AttachmentId( $payload['fileSize'] );
	}

}

final class Attachment extends AbstractAggregateRoot
{
	/** @var \AttachmentId */
	private $attachmentId;

	public static function upload( FileName $fileName, FilePath $filePath, MimeType $mimeType, FileSize $fileSize ) : self
	{
		$attachment = new self();

		$attachment->recordThat(
			new AttachmentUploadedEvent(
				AttachmentId::generate(),
				$fileName,
				$mimeType,
				$fileSize
			),
			$filePath
		);

		return $attachment;
	}

	protected function whenAttachmentUploaded( AttachmentUploadedEvent $event )
	{
		$this->attachmentId = $event->getAttachmentId();
	}

	public function getStreamType() : IdentifiesStreamType
	{
		return new AttachmentStreamType();
	}

	public function getStreamId() : IdentifiesStream
	{
		return $this->attachmentId;
	}
}

$attachment = Attachment::upload(
	new FileName( 'An example PDF' ),
	new FilePath( '/tmp/example.pdf.tmp' ),
	new MimeType( 'application/x-pdf' ),
	new FileSize( 150000 )
);

print_r( $attachment );
