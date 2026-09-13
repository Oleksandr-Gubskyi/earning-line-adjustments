<?php

declare(strict_types=1);

namespace Payroll\Infrastructure\EventStore\Exception;

use RuntimeException;

final class CorruptedEventRow extends RuntimeException
{
    public static function unexpectedColumn(string $column, string $expected): self
    {
        return new self(sprintf(
            'Stored event row column "%s" is not %s.',
            $column,
            $expected,
        ));
    }
}
