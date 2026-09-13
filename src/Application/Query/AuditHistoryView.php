<?php

declare(strict_types=1);

namespace Payroll\Application\Query;

use Payroll\Domain\Money;

/**
 * Everything needed to render the history of a line, and nothing else.
 *
 * A read model, not an aggregate: the query never loads EarningLine and never
 * returns it. That is where the separation in this design actually lives.
 */
final readonly class AuditHistoryView
{
    /**
     * @param  list<AdjustmentEntry>  $adjustments
     */
    public function __construct(
        public string $lineId,
        public bool $isFrozen,
        public Money $systemValue,
        public array $adjustments,
        public Money $adjustmentsTotal,
        public Money $currentValue,
    ) {}
}
