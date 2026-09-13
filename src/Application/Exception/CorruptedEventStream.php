<?php

declare(strict_types=1);

namespace Payroll\Application\Exception;

use RuntimeException;

final class CorruptedEventStream extends RuntimeException
{
    public static function expectedVersion(int $expected, int $actual): self
    {
        return new self(sprintf(
            'Event stream is not contiguous: expected version %d, found %d.',
            $expected,
            $actual,
        ));
    }
}
