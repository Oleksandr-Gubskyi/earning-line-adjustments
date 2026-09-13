<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\EarningLine;

use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Exception\InvalidEarningLineId;
use PHPUnit\Framework\TestCase;

final class EarningLineIdTest extends TestCase
{
    public function test_it_generates_distinct_identifiers(): void
    {
        $first = EarningLineId::generate();
        $second = EarningLineId::generate();

        self::assertNotSame($first->toString(), $second->toString());
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first->toString(),
        );
    }

    public function test_it_round_trips_through_a_string(): void
    {
        $id = EarningLineId::generate();

        self::assertTrue(EarningLineId::fromString($id->toString())->equals($id));
    }

    public function test_it_normalises_case(): void
    {
        $upper = '3F2504E0-4F89-41D3-9A0C-0305E82C3301';

        self::assertSame(strtolower($upper), EarningLineId::fromString($upper)->toString());
    }

    public function test_it_compares_by_value(): void
    {
        $value = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

        self::assertTrue(EarningLineId::fromString($value)->equals(EarningLineId::fromString($value)));
        self::assertFalse(EarningLineId::fromString($value)->equals(EarningLineId::generate()));
    }

    public function test_it_rejects_a_value_that_is_not_a_uuid(): void
    {
        $this->expectException(InvalidEarningLineId::class);

        EarningLineId::fromString('line-1');
    }
}
