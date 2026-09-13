<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Exception;

use DomainException;

final class AdjustmentAmountMustNotBeZero extends DomainException
{
    public static function create(): self
    {
        return new self(
            'A manual adjustment must change the line by a positive or negative amount. '
            .'A zero adjustment changes nothing and would only add noise to the audit history.'
        );
    }
}
