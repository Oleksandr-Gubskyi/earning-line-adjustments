<?php

declare(strict_types=1);

namespace Payroll\Application\Port;

use Payroll\Application\Exception\ConcurrencyConflict;
use Payroll\Application\Stream\EventStream;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Event\DomainEvent;

interface EventStore
{
    /**
     * Returns the full stream in version order, or an empty stream if there is none.
     */
    public function load(EarningLineId $id): EventStream;

    /**
     * Appends events atomically, but only if the stream is still at $expectedVersion.
     *
     * All events land or none do, so a stream can never hold half of what a single
     * decision produced.
     *
     * @param  list<DomainEvent>  $events
     *
     * @throws ConcurrencyConflict
     */
    public function append(EarningLineId $id, int $expectedVersion, array $events): void;
}
