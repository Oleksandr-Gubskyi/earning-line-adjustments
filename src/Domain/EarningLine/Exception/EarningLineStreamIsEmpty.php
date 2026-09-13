<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Exception;

use DomainException;

final class EarningLineStreamIsEmpty extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Cannot rebuild earning line "%s" from an empty event stream.', $id));
    }
}
