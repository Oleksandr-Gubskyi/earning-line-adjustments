<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Event;

use Payroll\Domain\Money;

/**
 * The first manual adjustment permanently froze the system-calculated value.
 *
 * Emitted once per line, in the same append as the adjustment that caused it.
 *
 * This is the freeze as a first-class fact rather than something inferred. Without
 * it the audit query would have to reconstruct the rule -- "the last recalculation
 * before the first adjustment" -- putting the most important business rule of this
 * domain in two places at once.
 */
final readonly class SystemValueFrozen implements DomainEvent
{
    public function __construct(public Money $frozenValue) {}
}
