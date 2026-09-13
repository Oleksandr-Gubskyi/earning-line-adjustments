<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\EarningLine;

use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLine;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\ManualAdjustmentAdded;
use Payroll\Domain\EarningLine\Event\SystemValueFrozen;
use Payroll\Domain\EarningLine\Event\SystemValueRecalculated;
use Payroll\Domain\EarningLine\Exception\AdjustmentAmountMustNotBeZero;
use Payroll\Domain\EarningLine\Exception\EarningLineStreamIsEmpty;
use Payroll\Domain\EarningLine\RecalculationResult;
use Payroll\Domain\Exception\InvalidMoneyAmount;
use Payroll\Domain\Money;
use PHPUnit\Framework\TestCase;

final class EarningLineTest extends TestCase
{
    public function test_a_calculated_line_starts_at_the_system_value(): void
    {
        $line = self::calculatedLine('1000.00');

        self::assertSame('$1,000.00', $line->currentValue()->format());
        self::assertFalse($line->isFrozen());
        self::assertFalse($line->hasManualAdjustments());
        self::assertEquals([new EarningLineCalculated(Money::fromDecimalString('1000.00'))], $line->pendingEvents());
    }

    public function test_recalculation_before_any_adjustment_replaces_the_system_value(): void
    {
        $line = self::calculatedLine('1000.00');

        $result = $line->recalculate(Money::fromDecimalString('1050.00'));

        self::assertSame(RecalculationResult::Applied, $result);
        // Replaced, not accumulated: 1050.00, never 2050.00.
        self::assertSame('$1,050.00', $line->currentValue()->format());
    }

    public function test_repeated_recalculations_keep_replacing_rather_than_accumulating(): void
    {
        $line = self::calculatedLine('1000.00');

        (void) $line->recalculate(Money::fromDecimalString('1050.00'));
        (void) $line->recalculate(Money::fromDecimalString('980.00'));
        (void) $line->recalculate(Money::fromDecimalString('1050.00'));

        self::assertSame('$1,050.00', $line->currentValue()->format());
    }

    public function test_the_first_adjustment_freezes_the_value_that_was_in_effect(): void
    {
        $line = self::calculatedLine('1000.00');
        (void) $line->recalculate(Money::fromDecimalString('1050.00'));
        $line->commit();

        $line->addManualAdjustment(Money::fromDecimalString('-45.55'), self::comment());

        self::assertTrue($line->isFrozen());
        self::assertTrue($line->hasManualAdjustments());
        self::assertSame('$1,050.00', $line->systemValue()->format());
        self::assertSame('$1,004.45', $line->currentValue()->format());
    }

    public function test_the_freeze_and_the_adjustment_are_recorded_together(): void
    {
        $line = self::calculatedLine('1050.00');
        $line->commit();

        $line->addManualAdjustment(Money::fromDecimalString('-45.55'), self::comment());

        // One append, so a stream can never hold an adjustment without its freeze.
        $events = $line->pendingEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(SystemValueFrozen::class, $events[0]);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[1]);
        self::assertSame('$1,050.00', $events[0]->frozenValue->format());
    }

    public function test_recalculation_after_the_freeze_is_ignored(): void
    {
        $line = self::frozenLineAt('1050.00');
        $line->commit();

        $result = $line->recalculate(Money::fromDecimalString('1020.00'));

        self::assertSame(RecalculationResult::IgnoredBecauseFrozen, $result);
        self::assertSame('$1,050.00', $line->systemValue()->format());
        self::assertSame('$1,004.45', $line->currentValue()->format());
    }

    public function test_an_ignored_recalculation_records_nothing_at_all(): void
    {
        $line = self::frozenLineAt('1050.00');
        $line->commit();

        (void) $line->recalculate(Money::fromDecimalString('1020.00'));

        self::assertSame([], $line->pendingEvents());
        // calculated, recalculated, frozen, adjusted -- and nothing added since.
        self::assertSame(4, $line->version());
    }

    public function test_the_freeze_happens_only_once(): void
    {
        $line = self::frozenLineAt('1050.00');
        $line->commit();

        $line->addManualAdjustment(Money::fromDecimalString('100.10'), self::comment());

        $events = $line->pendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ManualAdjustmentAdded::class, $events[0]);
    }

    public function test_adjustments_accumulate_on_top_of_the_frozen_value(): void
    {
        $line = self::frozenLineAt('1050.00');

        $line->addManualAdjustment(Money::fromDecimalString('100.10'), self::comment());
        $line->addManualAdjustment(Money::fromDecimalString('-0.10'), self::comment());

        self::assertSame('$1,104.45', $line->currentValue()->format());
        self::assertSame('$54.45', $line->adjustmentsTotal()->format());
    }

    public function test_a_compensating_adjustment_leaves_both_entries_in_the_history(): void
    {
        $line = self::frozenLineAt('1050.00');
        $line->commit();

        $line->addManualAdjustment(Money::fromDecimalString('-0.20'), AdjustmentComment::fromString('Mistake'));
        $line->addManualAdjustment(
            Money::fromDecimalString('0.20'),
            AdjustmentComment::fromString('Correcting mistake in adjustment #4'),
        );

        // Nothing is edited away: the error and its correction both stay recorded.
        $amounts = array_map(
            static fn (ManualAdjustmentAdded $event): string => $event->amount->format(),
            $line->pendingEvents(),
        );

        self::assertSame(['-$0.20', '$0.20'], $amounts);
        self::assertSame('$1,004.45', $line->currentValue()->format());
    }

    public function test_a_negative_adjustment_reduces_the_line(): void
    {
        $line = self::frozenLineAt('1050.00');

        self::assertSame('$1,004.45', $line->currentValue()->format());
        self::assertTrue($line->adjustmentsTotal()->isNegative());
    }

    public function test_a_zero_adjustment_is_rejected(): void
    {
        $line = self::calculatedLine('1000.00');

        $this->expectException(AdjustmentAmountMustNotBeZero::class);

        $line->addManualAdjustment(Money::zero(), self::comment());
    }

    public function test_a_rejected_adjustment_leaves_the_line_untouched(): void
    {
        $line = self::calculatedLine('1000.00');
        $line->commit();

        try {
            $line->addManualAdjustment(Money::zero(), self::comment());
        } catch (AdjustmentAmountMustNotBeZero) {
            // expected
        }

        self::assertFalse($line->isFrozen());
        self::assertSame([], $line->pendingEvents());
    }

    public function test_an_adjustment_that_would_make_the_line_unreadable_is_rejected(): void
    {
        $line = EarningLine::calculate(EarningLineId::generate(), Money::fromMinorUnits(PHP_INT_MAX));
        $line->commit();

        // Both operands are valid on their own; the sum is not. Accepting this would
        // append an entry that makes currentValue() and the whole audit history throw
        // -- and the stream is append-only, so it could never be taken back.
        try {
            $line->addManualAdjustment(Money::fromMinorUnits(1), self::comment());
            self::fail('The adjustment should have been rejected.');
        } catch (InvalidMoneyAmount) {
            // expected
        }

        self::assertSame([], $line->pendingEvents(), 'Nothing may be recorded.');
        self::assertFalse($line->isFrozen(), 'The line must not have been frozen by a rejected command.');
        self::assertSame(PHP_INT_MAX, $line->currentValue()->minorUnits);
    }

    public function test_the_current_value_is_always_the_system_value_plus_the_adjustments(): void
    {
        $line = self::frozenLineAt('1050.00');
        $line->addManualAdjustment(Money::fromDecimalString('100.10'), self::comment());
        $line->addManualAdjustment(Money::fromDecimalString('-0.10'), self::comment());

        self::assertTrue(
            $line->currentValue()->equals($line->systemValue()->add($line->adjustmentsTotal())),
        );
    }

    public function test_reading_pending_events_does_not_consume_them(): void
    {
        $line = self::calculatedLine('1000.00');

        self::assertCount(1, $line->pendingEvents());
        self::assertCount(1, $line->pendingEvents());
    }

    public function test_committing_advances_the_version_and_clears_the_buffer(): void
    {
        $line = self::calculatedLine('1000.00');
        self::assertSame(0, $line->version());

        $line->commit();

        self::assertSame(1, $line->version());
        self::assertSame([], $line->pendingEvents());

        $line->addManualAdjustment(Money::fromDecimalString('-45.55'), self::comment());
        $line->commit();

        // Freeze plus adjustment is two events.
        self::assertSame(3, $line->version());
    }

    public function test_a_line_cannot_be_rebuilt_from_an_empty_stream(): void
    {
        $this->expectException(EarningLineStreamIsEmpty::class);

        EarningLine::reconstitute(EarningLineId::generate(), [], 0);
    }

    public function test_the_recalculation_event_carries_the_new_value(): void
    {
        $line = self::calculatedLine('1000.00');
        $line->commit();

        (void) $line->recalculate(Money::fromDecimalString('1050.00'));

        $events = $line->pendingEvents();
        self::assertInstanceOf(SystemValueRecalculated::class, $events[0]);
        self::assertSame('$1,050.00', $events[0]->systemValue->format());
    }

    private static function calculatedLine(string $systemValue): EarningLine
    {
        return EarningLine::calculate(EarningLineId::generate(), Money::fromDecimalString($systemValue));
    }

    /**
     * A line that has been recalculated, then frozen by a -45.55 adjustment.
     */
    private static function frozenLineAt(string $systemValue): EarningLine
    {
        $line = self::calculatedLine('1000.00');
        (void) $line->recalculate(Money::fromDecimalString($systemValue));
        $line->addManualAdjustment(Money::fromDecimalString('-45.55'), self::comment());

        return $line;
    }

    private static function comment(): AdjustmentComment
    {
        return AdjustmentComment::fromString('Employee declined dental benefit; reversing deduction');
    }
}
