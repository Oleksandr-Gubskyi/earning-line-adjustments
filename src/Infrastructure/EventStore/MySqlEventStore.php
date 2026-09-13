<?php

declare(strict_types=1);

namespace Payroll\Infrastructure\EventStore;

use DateTimeImmutable;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Payroll\Application\Exception\ConcurrencyConflict;
use Payroll\Application\Port\EventStore;
use Payroll\Application\Stream\EventStream;
use Payroll\Application\Stream\RecordedEvent;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Infrastructure\EventStore\Exception\CorruptedEventRow;

/**
 * The append-only event store, on a single MySQL table.
 *
 * Written with the query builder rather than Eloquent on purpose. This table has
 * no lifecycle: rows are inserted and then read, never updated, never deleted, and
 * they have no relations. An Eloquent model would add nothing and would create a
 * surface for a global scope or an observer to quietly alter how the source of
 * truth is read.
 */
final class MySqlEventStore implements EventStore
{
    private const TABLE = 'domain_events';

    private const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ConcurrencyErrorDetector $concurrencyErrors,
        private readonly EventSerializer $serializer = new EventSerializer,
    ) {}

    public function load(EarningLineId $id): EventStream
    {
        // The explicit ordering is load-bearing. Everything downstream depends on it,
        // including the audit numbering; without it the rows happen to come back in
        // the right order on a small table and stop doing so later.
        $rows = $this->connection->table(self::TABLE)
            ->where('stream_id', $id->toString())
            ->orderBy('stream_version')
            ->get(['stream_version', 'event_type', 'payload', 'recorded_at']);

        if ($rows->isEmpty()) {
            return EventStream::empty();
        }

        $recorded = [];

        foreach ($rows as $row) {
            $recorded[] = new RecordedEvent(
                $this->serializer->deserialize(
                    self::stringColumn($row, 'event_type'),
                    self::stringColumn($row, 'payload'),
                ),
                self::intColumn($row, 'stream_version'),
                self::timestampColumn($row, 'recorded_at'),
            );
        }

        return EventStream::fromRecordedEvents($recorded);
    }

    public function append(EarningLineId $id, int $expectedVersion, array $events): void
    {
        if ($events === []) {
            return;
        }

        $streamId = $id->toString();

        // One timestamp for the whole append. MySQL would do the same with NOW(6),
        // which is evaluated once per statement rather than once per row.
        $recordedAt = (new DateTimeImmutable)->format(self::TIMESTAMP_FORMAT);

        $rows = [];
        $version = $expectedVersion;

        foreach ($events as $event) {
            $serialized = $this->serializer->serialize($event);

            $rows[] = [
                'stream_id' => $streamId,
                'stream_version' => ++$version,
                'event_type' => $serialized['type'],
                'payload' => $serialized['payload'],
                'recorded_at' => $recordedAt,
            ];
        }

        try {
            // A single multi-row insert is already atomic in InnoDB -- a duplicate key
            // on any row rolls back the whole statement. The transaction is an explicit
            // boundary for the day this method does more than one thing, not the reason
            // partial writes cannot happen.
            $this->connection->transaction(function () use ($streamId, $expectedVersion, $rows): void {
                // The unique key alone is not the whole of optimistic concurrency: it
                // proves no one took these versions, not that the stream is actually at
                // the version the caller believed. Without this check, appending at a
                // version ahead of reality succeeds and leaves a gap that makes the
                // stream unreadable on the next load.
                if ($this->currentVersionOf($streamId) !== $expectedVersion) {
                    throw ConcurrencyConflict::atVersion($streamId, $expectedVersion);
                }

                $this->connection->table(self::TABLE)->insert($rows);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Laravel has already narrowed this to an actual duplicate key rather than
            // any integrity violation. This table carries exactly one unique key and no
            // foreign keys, so the only thing it can mean is a competing writer.
            // Adding a second constraint would invalidate that reasoning.
            throw ConcurrencyConflict::atVersion($streamId, $expectedVersion, $e);
        } catch (QueryException $e) {
            // A deadlock or a lock-wait timeout means the same thing to a caller:
            // the optimistic attempt did not go through, reload and decide again.
            // It does NOT arrive as DeadlockException here -- the framework only
            // raises that from a nested transaction.
            if ($this->concurrencyErrors->causedByConcurrencyError($e)) {
                throw ConcurrencyConflict::atVersion($streamId, $expectedVersion, $e);
            }

            throw $e;
        }
    }

    private function currentVersionOf(string $streamId): int
    {
        $highest = $this->connection->table(self::TABLE)
            ->where('stream_id', $streamId)
            ->max('stream_version');

        if ($highest === null) {
            return 0;
        }

        return is_int($highest) ? $highest : (int) (is_string($highest) ? $highest : 0);
    }

    private static function stringColumn(object $row, string $column): string
    {
        $value = $row->{$column} ?? null;

        if (! is_string($value)) {
            throw CorruptedEventRow::unexpectedColumn($column, 'a string');
        }

        return $value;
    }

    private static function intColumn(object $row, string $column): int
    {
        $value = $row->{$column} ?? null;

        if (is_int($value)) {
            return $value;
        }

        // Depending on driver settings an integer column can arrive as a numeric string.
        if (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }

        throw CorruptedEventRow::unexpectedColumn($column, 'an integer');
    }

    private static function timestampColumn(object $row, string $column): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            self::TIMESTAMP_FORMAT,
            self::stringColumn($row, $column),
        );

        if ($parsed === false) {
            throw CorruptedEventRow::unexpectedColumn($column, 'a timestamp with microseconds');
        }

        return $parsed;
    }
}
