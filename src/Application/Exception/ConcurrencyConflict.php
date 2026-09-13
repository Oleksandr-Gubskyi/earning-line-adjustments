<?php

declare(strict_types=1);

namespace Payroll\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * Someone else wrote to this stream since it was loaded.
 *
 * Raised when the expected version no longer matches what the store holds. The
 * caller's move is to reload the stream and decide again, never to retry blindly.
 */
final class ConcurrencyConflict extends RuntimeException
{
    public static function atVersion(string $streamId, int $expectedVersion, ?Throwable $previous = null): self
    {
        // The original database error is kept: a duplicate key, a deadlock and a
        // lock-wait timeout all arrive here as the same conflict, and without the
        // cause they become indistinguishable when something needs diagnosing.
        return new self(
            sprintf(
                'Concurrent write to stream "%s": expected it to be at version %d.',
                $streamId,
                $expectedVersion,
            ),
            0,
            $previous,
        );
    }
}
