<?php

declare(strict_types=1);

namespace Payroll\Application\Stream;

use DateTimeImmutable;
use Payroll\Domain\EarningLine\Event\DomainEvent;

/**
 * A domain event together with the store metadata that surrounds it.
 *
 * Keeping position and timestamp here rather than inside the event itself is what
 * lets domain events stay ignorant of persistence. The read side needs both; the
 * aggregate needs neither.
 */
final readonly class RecordedEvent
{
    public function __construct(
        public DomainEvent $event,
        public int $streamVersion,
        public DateTimeImmutable $recordedAt,
    ) {}
}
