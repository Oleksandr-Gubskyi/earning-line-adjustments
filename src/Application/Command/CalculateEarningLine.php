<?php

declare(strict_types=1);

namespace Payroll\Application\Command;

use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\Money;

/**
 * Commands carry value objects rather than raw input, so a command that exists is
 * already well formed. Parsing and validating happen at the edge -- in the Artisan
 * command -- where a bad value can still be reported to whoever typed it.
 */
final readonly class CalculateEarningLine
{
    public function __construct(
        public EarningLineId $id,
        public Money $systemValue,
    ) {}
}
