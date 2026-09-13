<?php

declare(strict_types=1);

namespace Payroll\Application\Command;

use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\Money;

final readonly class RecalculateSystemValue
{
    public function __construct(
        public EarningLineId $id,
        public Money $systemValue,
    ) {}
}
