<?php

declare(strict_types=1);

namespace Payroll\Domain\Exception;

use InvalidArgumentException;

final class InvalidMoneyAmount extends InvalidArgumentException
{
    public static function notADecimalAmount(string $amount): self
    {
        return new self(sprintf(
            'Expected a decimal amount with at most two fractional digits, got "%s".',
            $amount,
        ));
    }

    public static function outOfRange(string $amount): self
    {
        return new self(sprintf('Amount "%s" does not fit in an integer number of cents.', $amount));
    }

    public static function additionOverflowed(): self
    {
        return new self('Adding these amounts overflows the integer range.');
    }
}
