<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Event;

use Payroll\Domain\Money;

/**
 * A payroll specialist corrected the line by a signed amount, with a reason.
 *
 * The comment is a plain string, not an AdjustmentComment: the command side
 * validates it before this event exists, and a recorded event is a historical
 * fact. Re-running validation during replay would make old streams unreadable
 * the day a validation rule is tightened.
 *
 * The stream of these events is the adjustment history. There is no adjustment
 * entity inside the aggregate, and the audit numbering (#1, #2, ...) is the
 * ordinal of each event among these in the version-ordered stream.
 */
final readonly class ManualAdjustmentAdded implements DomainEvent
{
    public function __construct(
        public Money $amount,
        public string $comment,
    ) {}
}
