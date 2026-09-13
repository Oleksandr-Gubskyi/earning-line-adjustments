<?php

declare(strict_types=1);

namespace Payroll\Application\Stream;

use Payroll\Application\Exception\CorruptedEventStream;
use Payroll\Domain\EarningLine\Event\DomainEvent;

/**
 * The recorded history of one aggregate, in version order, with its current version.
 *
 * Version contiguity is checked here rather than assumed by callers. Deriving the
 * version from a plain count works only while the stream is complete and gap-free,
 * and it breaks silently the day someone adds filtering or paging to a load(). This
 * turns that whole class of bug into an impossible state.
 */
final readonly class EventStream
{
    /**
     * @param  list<RecordedEvent>  $events
     */
    private function __construct(
        public array $events,
        public int $currentVersion,
    ) {}

    public static function empty(): self
    {
        return new self([], 0);
    }

    /**
     * @param  list<RecordedEvent>  $events
     */
    public static function fromRecordedEvents(array $events): self
    {
        $expected = 1;

        foreach ($events as $recorded) {
            if ($recorded->streamVersion !== $expected) {
                throw CorruptedEventStream::expectedVersion($expected, $recorded->streamVersion);
            }

            $expected++;
        }

        return new self($events, count($events));
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    /**
     * @return list<DomainEvent>
     */
    public function domainEvents(): array
    {
        return array_map(
            static fn (RecordedEvent $recorded): DomainEvent => $recorded->event,
            $this->events,
        );
    }
}
