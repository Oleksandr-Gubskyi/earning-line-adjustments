<?php

declare(strict_types=1);

namespace Payroll\Application\Command;

use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\Money;

final readonly class AddManualAdjustment
{
    public function __construct(
        public EarningLineId $id,
        public Money $amount,
        public AdjustmentComment $comment,
    ) {}
}
