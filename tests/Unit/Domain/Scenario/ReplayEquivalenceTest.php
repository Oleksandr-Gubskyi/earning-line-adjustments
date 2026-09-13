<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Scenario;

use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLine;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\RecalculationResult;
use Payroll\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * A line rebuilt from its stream must be indistinguishable from the live one.
 *
 * This is the test that keeps event sourcing honest here. The classic way to break
 * it is to let a command method touch a field directly instead of recording an
 * event, or to let reconstitute() go through recordThat(). Comparing getters alone
 * catches neither, so this also checks the version, the pending buffer, and what
 * both aggregates do when given the same next command.
 */
final class ReplayEquivalenceTest extends TestCase
{
    public function test_a_rebuilt_line_matches_the_live_one_in_every_field(): void
    {
        [$live, $rebuilt] = self::liveAndRebuilt();

        self::assertSame($live->systemValue()->minorUnits, $rebuilt->systemValue()->minorUnits);
        self::assertSame($live->adjustmentsTotal()->minorUnits, $rebuilt->adjustmentsTotal()->minorUnits);
        self::assertSame($live->currentValue()->minorUnits, $rebuilt->currentValue()->minorUnits);
        self::assertSame($live->isFrozen(), $rebuilt->isFrozen());
        self::assertSame($live->hasManualAdjustments(), $rebuilt->hasManualAdjustments());
        self::assertSame($live->version(), $rebuilt->version());
        self::assertTrue($live->id->equals($rebuilt->id));

        self::assertSame('$1,104.45', $rebuilt->currentValue()->format());
    }

    public function test_a_rebuilt_line_has_nothing_pending(): void
    {
        [, $rebuilt] = self::liveAndRebuilt();

        // If reconstitute() went through recordThat(), the whole replayed history
        // would sit here and be written to the stream a second time on the next save.
        self::assertSame([], $rebuilt->pendingEvents());
    }

    public function test_both_lines_react_identically_to_the_next_command(): void
    {
        [$live, $rebuilt] = self::liveAndRebuilt();

        $amount = Money::fromDecimalString('-12.34');
        $comment = AdjustmentComment::fromString('Later correction');

        $live->addManualAdjustment($amount, $comment);
        $rebuilt->addManualAdjustment($amount, $comment);

        self::assertEquals($live->pendingEvents(), $rebuilt->pendingEvents());
        self::assertSame($live->currentValue()->minorUnits, $rebuilt->currentValue()->minorUnits);
    }

    public function test_a_rebuilt_frozen_line_still_refuses_recalculation(): void
    {
        [, $rebuilt] = self::liveAndRebuilt();

        $result = $rebuilt->recalculate(Money::fromDecimalString('999.99'));

        self::assertSame(RecalculationResult::IgnoredBecauseFrozen, $result);
        self::assertSame('$1,104.45', $rebuilt->currentValue()->format());
        self::assertSame([], $rebuilt->pendingEvents());
    }

    public function test_a_line_rebuilt_from_a_stream_without_a_freeze_is_still_open(): void
    {
        // The freeze state is derived from the stream, never assumed.
        $live = EarningLine::calculate(EarningLineId::generate(), Money::fromDecimalString('1000.00'));
        (void) $live->recalculate(Money::fromDecimalString('1050.00'));

        $events = $live->pendingEvents();
        $rebuilt = EarningLine::reconstitute($live->id, $events, count($events));

        self::assertFalse($rebuilt->isFrozen());
        self::assertSame(
            RecalculationResult::Applied,
            $rebuilt->recalculate(Money::fromDecimalString('1075.00')),
        );
        self::assertSame('$1,075.00', $rebuilt->currentValue()->format());
    }

    /**
     * @return array{EarningLine, EarningLine}
     */
    private static function liveAndRebuilt(): array
    {
        $live = self::runAssignmentScenario();

        $events = $live->pendingEvents();
        $live->commit();

        $rebuilt = EarningLine::reconstitute($live->id, $events, count($events));

        return [$live, $rebuilt];
    }

    private static function runAssignmentScenario(): EarningLine
    {
        $line = EarningLine::calculate(EarningLineId::generate(), Money::fromDecimalString('1000.00'));
        (void) $line->recalculate(Money::fromDecimalString('1050.00'));

        foreach ([
            ['-45.55', 'Employee declined dental benefit; reversing deduction'],
            ['100.10', 'Late correction: missed approved overtime bonus'],
            ['-0.10', 'Minor rounding adjustment'],
            ['-0.20', 'Second minor rounding adjustment'],
            ['0.20', 'Correcting mistake in adjustment #4'],
        ] as [$amount, $comment]) {
            $line->addManualAdjustment(
                Money::fromDecimalString($amount),
                AdjustmentComment::fromString($comment),
            );
        }

        (void) $line->recalculate(Money::fromDecimalString('1020.00'));

        return $line;
    }
}
