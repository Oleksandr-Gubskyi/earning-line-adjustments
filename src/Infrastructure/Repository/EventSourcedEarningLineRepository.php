<?php

declare(strict_types=1);

namespace Payroll\Infrastructure\Repository;

use Payroll\Application\Exception\EarningLineNotFound;
use Payroll\Application\Port\EarningLineRepository;
use Payroll\Application\Port\EventStore;
use Payroll\Domain\EarningLine\EarningLine;
use Payroll\Domain\EarningLine\EarningLineId;

/**
 * Turns a stream into an aggregate and back again.
 *
 * It exists so the three handlers do not each repeat load, rebuild, collect,
 * version and append. That is real duplication across three call sites rather
 * than a layer added for symmetry.
 */
final readonly class EventSourcedEarningLineRepository implements EarningLineRepository
{
    public function __construct(private EventStore $events) {}

    public function get(EarningLineId $id): EarningLine
    {
        $stream = $this->events->load($id);

        if ($stream->isEmpty()) {
            throw EarningLineNotFound::withId($id->toString());
        }

        return EarningLine::reconstitute($id, $stream->domainEvents(), $stream->currentVersion);
    }

    public function save(EarningLine $line): void
    {
        $pending = $line->pendingEvents();

        // A command that changed nothing -- an ignored recalculation, for instance --
        // must not cost a round trip or touch the stream.
        if ($pending === []) {
            return;
        }

        $this->events->append($line->id, $line->version(), $pending);

        // Only after the append succeeded: a failed write must leave the events in
        // the aggregate rather than silently dropping them.
        $line->commit();
    }
}
