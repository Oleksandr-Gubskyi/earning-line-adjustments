<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Payroll\Domain\Exception\InvalidMoneyAmount;
use Payroll\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_holds_minor_units(): void
    {
        self::assertSame(110445, Money::fromMinorUnits(110445)->minorUnits);
        self::assertSame(0, Money::zero()->minorUnits);
        self::assertSame(-4555, Money::fromMinorUnits(-4555)->minorUnits);
    }

    #[DataProvider('decimalStrings')]
    public function test_it_parses_decimal_strings(string $input, int $expectedMinorUnits): void
    {
        self::assertSame($expectedMinorUnits, Money::fromDecimalString($input)->minorUnits);
    }

    public static function decimalStrings(): iterable
    {
        // Every amount the assignment uses.
        yield 'initial system value' => ['1000.00', 100000];
        yield 'recalculated value' => ['1050.00', 105000];
        yield 'first adjustment' => ['-45.55', -4555];
        yield 'second adjustment' => ['100.10', 10010];
        yield 'third adjustment' => ['-0.10', -10];
        yield 'fourth adjustment' => ['-0.20', -20];
        yield 'expected total' => ['1104.45', 110445];

        yield 'no fractional part' => ['17', 1700];
        yield 'single fractional digit means tenths' => ['0.2', 20];
        yield 'two fractional digits' => ['0.02', 2];
        yield 'explicit plus sign' => ['+5.00', 500];
        yield 'negative zero is zero' => ['-0.00', 0];
        yield 'surrounding whitespace' => ['  1.50  ', 150];
        yield 'leading zeros' => ['007.05', 705];
    }

    /**
     * These are the values a float-based parser gets wrong:
     * (int) ((float) "0.29" * 100) is 28, not 29.
     *
     * None of them appears in the assignment, which is what makes the bug dangerous --
     * the scenario test would pass while the parser was broken.
     */
    #[DataProvider('valuesAFloatParserGetsWrong')]
    public function test_it_is_exact_where_a_float_parser_is_not(string $input, int $expected): void
    {
        self::assertSame($expected, Money::fromDecimalString($input)->minorUnits);
        self::assertNotSame($expected, (int) ((float) $input * 100), 'This value is no longer a float trap.');
    }

    public static function valuesAFloatParserGetsWrong(): iterable
    {
        yield '0.29' => ['0.29', 29];
        yield '1.15' => ['1.15', 115];
        yield '8.20' => ['8.20', 820];
    }

    public function test_it_parses_every_cent_in_a_range_exactly(): void
    {
        for ($cents = 1; $cents <= 20_000; $cents++) {
            $decimal = sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);

            self::assertSame($cents, Money::fromDecimalString($decimal)->minorUnits, $decimal);
        }
    }

    #[DataProvider('malformedAmounts')]
    public function test_it_rejects_malformed_amounts(string $input): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::fromDecimalString($input);
    }

    public static function malformedAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'not a number' => ['abc'];
        yield 'three fractional digits' => ['1.234'];
        yield 'thousands separator' => ['1,50'];
        yield 'two decimal points' => ['1.2.3'];
        yield 'scientific notation' => ['1e3'];
        yield 'missing integer part' => ['.5'];
        yield 'trailing decimal point' => ['5.'];
        yield 'currency symbol' => ['$5.00'];
        yield 'unicode minus sign' => ["\u{2212}45.55"];
    }

    public function test_it_rejects_amounts_that_do_not_fit_an_integer(): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        Money::fromDecimalString('99999999999999999999.99');
    }

    public function test_it_adds_without_mutating_either_operand(): void
    {
        $frozen = Money::fromDecimalString('1050.00');
        $adjustment = Money::fromDecimalString('-45.55');

        $result = $frozen->add($adjustment);

        self::assertSame(100445, $result->minorUnits);
        self::assertSame(105000, $frozen->minorUnits);
        self::assertSame(-4555, $adjustment->minorUnits);
    }

    public function test_it_rejects_addition_that_overflows(): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        // The (void) cast is how PHP 8.5 lets a caller intentionally discard a
        // #[\NoDiscard] result. Without it this line raises a warning, which the
        // suite treats as a failure -- the attribute is an enforced rule here.
        (void) Money::fromMinorUnits(PHP_INT_MAX)->add(Money::fromMinorUnits(1));
    }

    public function test_the_supported_range_is_symmetric(): void
    {
        // PHP_INT_MIN is excluded on purpose: -PHP_INT_MIN does not fit in an int and
        // would silently become a float, which would make negate() a partial function
        // in a class whose whole point is that money never touches floating point.
        self::assertSame(PHP_INT_MAX, Money::fromMinorUnits(PHP_INT_MAX)->minorUnits);
        self::assertSame(-PHP_INT_MAX, Money::fromMinorUnits(-PHP_INT_MAX)->minorUnits);

        $this->expectException(InvalidMoneyAmount::class);
        Money::fromMinorUnits(PHP_INT_MIN);
    }

    public function test_negating_the_smallest_supported_amount_is_safe(): void
    {
        self::assertSame(PHP_INT_MAX, Money::fromMinorUnits(-PHP_INT_MAX)->negate()->minorUnits);
    }

    public function test_it_rejects_addition_that_overflows_downwards(): void
    {
        $this->expectException(InvalidMoneyAmount::class);

        (void) Money::fromMinorUnits(-PHP_INT_MAX)->add(Money::fromMinorUnits(-1));
    }

    public function test_it_negates(): void
    {
        self::assertSame(-2000, Money::fromDecimalString('20.00')->negate()->minorUnits);
        self::assertSame(2000, Money::fromDecimalString('-20.00')->negate()->minorUnits);
        self::assertSame(0, Money::zero()->negate()->minorUnits);
    }

    public function test_it_reports_zero_and_sign(): void
    {
        self::assertTrue(Money::zero()->isZero());
        self::assertFalse(Money::fromMinorUnits(-1)->isZero());

        self::assertTrue(Money::fromMinorUnits(-1)->isNegative());
        self::assertFalse(Money::zero()->isNegative());
        self::assertFalse(Money::fromMinorUnits(1)->isNegative());
    }

    public function test_it_compares_by_value(): void
    {
        self::assertTrue(Money::fromMinorUnits(500)->equals(Money::fromDecimalString('5.00')));
        self::assertFalse(Money::fromMinorUnits(500)->equals(Money::fromMinorUnits(-500)));
    }

    #[DataProvider('formattedAmounts')]
    public function test_it_formats_the_way_the_assignment_does(int $minorUnits, string $expected): void
    {
        self::assertSame($expected, Money::fromMinorUnits($minorUnits)->format());
    }

    public static function formattedAmounts(): iterable
    {
        yield 'final value' => [110445, '$1,104.45'];
        yield 'frozen system value' => [105000, '$1,050.00'];
        yield 'negative adjustment' => [-4555, '-$45.55'];
        yield 'small negative keeps leading zero' => [-10, '-$0.10'];
        yield 'zero' => [0, '$0.00'];
        yield 'millions get separators' => [123456789, '$1,234,567.89'];
    }
}
