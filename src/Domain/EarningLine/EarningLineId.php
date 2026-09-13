<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine;

use Payroll\Domain\EarningLine\Exception\InvalidEarningLineId;
use Ramsey\Uuid\Uuid;

final readonly class EarningLineId
{
    private function __construct(public string $value) {}

    public static function generate(): self
    {
        return new self(Uuid::uuid4()->toString());
    }

    public static function fromString(string $value): self
    {
        if (! Uuid::isValid($value)) {
            throw InvalidEarningLineId::notAUuid($value);
        }

        return new self(strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
