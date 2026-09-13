<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Exception;

use InvalidArgumentException;

final class InvalidAdjustmentComment extends InvalidArgumentException
{
    public static function isEmpty(): self
    {
        return new self('A manual adjustment requires a comment explaining why it was made.');
    }

    public static function isNotValidUtf8(): self
    {
        return new self('Adjustment comment must be valid UTF-8.');
    }
}
