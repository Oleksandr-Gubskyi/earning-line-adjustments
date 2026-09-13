<?php

declare(strict_types=1);

namespace Payroll\Application\Exception;

use RuntimeException;

/**
 * Someone else wrote to this stream since it was loaded.
 *
 * Raised when the expected version no longer matches what the store holds. The
 * caller's move is to reload the stream and decide again, never to retry blindly.
 */
final class ConcurrencyConflict extends RuntimeException
{
    public static function atVersion(string $streamId, int $expectedVersion): self
    {
        return new self(sprintf(
            'Concurrent write to stream "%s": expected it to be at version %d.',
            $streamId,
            $expectedVersion,
        ));
    }
}
