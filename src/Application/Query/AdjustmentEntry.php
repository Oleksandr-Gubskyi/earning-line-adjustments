<?php

declare(strict_types=1);

namespace Payroll\Application\Query;

use DateTimeImmutable;
use Payroll\Domain\Money;

/**
 * One line of the audit history.
 *
 * $number is the ordinal among adjustments, which is what the assignment's table
 * means by "Adjustment 1..5" and what the step 8 comment refers to as "#4". It is
 * NOT the stream version: the stream also holds the calculation, the recalculation
 * and the freeze, so the first adjustment sits at version 4.
 */
final readonly class AdjustmentEntry
{
    public function __construct(
        public int $number,
        public Money $amount,
        public string $comment,
        public Money $valueAfter,
        public DateTimeImmutable $recordedAt,
    ) {}
}
