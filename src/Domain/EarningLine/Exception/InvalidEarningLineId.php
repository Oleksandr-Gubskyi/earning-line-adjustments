<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Exception;

use InvalidArgumentException;

final class InvalidEarningLineId extends InvalidArgumentException
{
    public static function notAUuid(string $value): self
    {
        return new self(sprintf('Earning line id must be a UUID, got "%s".', $value));
    }
}
