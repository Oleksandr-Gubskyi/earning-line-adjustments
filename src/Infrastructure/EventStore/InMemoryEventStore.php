<?php

declare(strict_types=1);

namespace Payroll\Infrastructure\EventStore;

use DateTimeImmutable;
use Payroll\Application\Exception\ConcurrencyConflict;
use Payroll\Application\Port\EventStore;
use Payroll\Application\Stream\EventStream;
use Payroll\Application\Stream\RecordedEvent;
use Payroll\Domain\EarningLine\EarningLineId;

/**
 * An event store that keeps streams in process memory.
 *
 * It stores exactly what the SQL store stores -- a logical type and a JSON payload,
 * not the event objects themselves -- and shares the same serializer. That costs a
 * few lines and buys a genuine stand-in: every domain test round-trips through
 * serialization, so a payload that survives PHP but not JSON fails in the fast
 * suite rather than only in the integration one.
 *
 * Both stores are held to the same behaviour by a shared contract test.
 */
final class InMemoryEventStore implements EventStore
{
    /**
     * @var array<string, list<array{type: string, payload: string, version: int, recorded_at: DateTimeImmutable}>>
     */
    private array $streams = [];

    public function __construct(private readonly EventSerializer $serializer = new EventSerializer) {}

    public function load(EarningLineId $id): EventStream
    {
        $rows = $this->streams[$id->toString()] ?? [];

        if ($rows === []) {
            return EventStream::empty();
        }

        return EventStream::fromRecordedEvents(array_map(
            fn (array $row): RecordedEvent => new RecordedEvent(
                $this->serializer->deserialize($row['type'], $row['payload']),
                $row['version'],
                $row['recorded_at'],
            ),
            $rows,
        ));
    }

    public function append(EarningLineId $id, int $expectedVersion, array $events): void
    {
        if ($events === []) {
            return;
        }

        $streamId = $id->toString();
        $currentVersion = count($this->streams[$streamId] ?? []);

        if ($currentVersion !== $expectedVersion) {
            throw ConcurrencyConflict::atVersion($streamId, $expectedVersion);
        }

        // One timestamp for the whole append, matching the SQL store, where NOW(6)
        // is evaluated once per statement. Ordering comes from the version, never
        // from the clock.
        $recordedAt = new DateTimeImmutable;
        $version = $expectedVersion;
        $rows = [];

        // Every row is built before any of them is stored. Serializing straight into
        // the stream would let a failure on the third event leave the first two
        // behind, which the SQL store cannot do -- it serializes the whole batch
        // before it issues a single INSERT.
        foreach ($events as $event) {
            $serialized = $this->serializer->serialize($event);

            $rows[] = [
                'type' => $serialized['type'],
                'payload' => $serialized['payload'],
                'version' => ++$version,
                'recorded_at' => $recordedAt,
            ];
        }

        foreach ($rows as $row) {
            $this->streams[$streamId][] = $row;
        }
    }
}
