<?php

declare(strict_types=1);

namespace Payroll\Domain;

use Payroll\Domain\Exception\InvalidMoneyAmount;

/**
 * A monetary amount held as a signed integer number of minor units (cents).
 *
 * Money never touches floating point, including while parsing. The obvious
 * `(int) ((float) $amount * 100)` is wrong for roughly one amount in fifteen --
 * "0.29" becomes 28 -- and it happens to be correct for every value in the
 * assignment, which is precisely what makes it dangerous: the scenario test
 * would stay green while the parser was broken.
 */
final readonly class Money
{
    /**
     * PHP_INT_MIN is excluded so the range is symmetric and negate() is total:
     * -PHP_INT_MIN does not fit in an int and would silently become a float.
     */
    public const int MAX_MINOR_UNITS = PHP_INT_MAX;

    public const int MIN_MINOR_UNITS = -PHP_INT_MAX;

    private function __construct(public int $minorUnits) {}

    public static function fromMinorUnits(int $minorUnits): self
    {
        if ($minorUnits < self::MIN_MINOR_UNITS) {
            throw InvalidMoneyAmount::outOfRange((string) $minorUnits);
        }

        return new self($minorUnits);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parses "1050.00", "-45.55", "0.2" or "17" into minor units, by string only.
     */
    public static function fromDecimalString(string $amount): self
    {
        $pattern = '/^(?<sign>[+-]?)(?<units>\d+)(?:\.(?<cents>\d{1,2}))?$/';

        if (preg_match($pattern, trim($amount), $matches) !== 1) {
            throw InvalidMoneyAmount::notADecimalAmount($amount);
        }

        // str_pad right-pads, so "2" means twenty cents and "02" means two.
        $digits = ltrim(ltrim($matches['units'], '0').str_pad($matches['cents'] ?? '', 2, '0'), '0');

        if ($digits === '') {
            $digits = '0';
        }

        $value = (int) $digits;

        // $digits carries no leading zeros, so a mismatch here means the cast saturated.
        if ((string) $value !== $digits) {
            throw InvalidMoneyAmount::outOfRange($amount);
        }

        return new self($matches['sign'] === '-' ? -$value : $value);
    }

    #[\NoDiscard]
    public function add(self $other): self
    {
        // Checked before the addition rather than after: PHP silently widens an
        // overflowing int to float, and a post-hoc is_int() check reads as dead
        // code to static analysis even though it fires at runtime.
        $overflows = $other->minorUnits > 0
            ? $this->minorUnits > self::MAX_MINOR_UNITS - $other->minorUnits
            : $this->minorUnits < self::MIN_MINOR_UNITS - $other->minorUnits;

        if ($overflows) {
            throw InvalidMoneyAmount::additionOverflowed();
        }

        return new self($this->minorUnits + $other->minorUnits);
    }

    #[\NoDiscard]
    public function negate(): self
    {
        return new self(-$this->minorUnits);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }

    /**
     * Renders the amount the way the assignment's tables do: "$1,104.45", "-$45.55".
     */
    public function format(): string
    {
        $negative = $this->isNegative();
        $units = intdiv($this->minorUnits, 100);
        $cents = $this->minorUnits % 100;

        if ($negative) {
            $units = -$units;
            $cents = -$cents;
        }

        return sprintf('%s$%s.%02d', $negative ? '-' : '', number_format($units), $cents);
    }
}
