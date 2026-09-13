<?php

declare(strict_types=1);

namespace Payroll\Application\Exception;

use RuntimeException;

/**
 * Raised by the repository, not by the aggregate: an aggregate cannot assert its
 * own absence, and rebuilding one from an empty stream would otherwise fail with
 * an uninitialised property rather than a meaningful error.
 */
final class EarningLineNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('No earning line exists with id "%s".', $id));
    }
}
