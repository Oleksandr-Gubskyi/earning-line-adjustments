<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Event;

use Payroll\Domain\Money;

/**
 * Source data changed and the system recalculated the line.
 *
 * Recorded only while the line is still open: the new value replaces the old one
 * rather than accumulating. A recalculation attempted after the freeze records
 * nothing at all, because it changes nothing.
 */
final readonly class SystemValueRecalculated implements DomainEvent
{
    public function __construct(public Money $systemValue) {}
}
