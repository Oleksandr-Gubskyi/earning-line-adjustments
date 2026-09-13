<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Scenario;

use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLine;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Event\DomainEvent;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\ManualAdjustmentAdded;
use Payroll\Domain\EarningLine\Event\SystemValueFrozen;
use Payroll\Domain\EarningLine\Event\SystemValueRecalculated;
use Payroll\Domain\EarningLine\RecalculationResult;
use Payroll\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * The worked example from the assignment, step by step, at the aggregate level.
 *
 * This runs without a database or any framework, so the number the whole exercise
 * turns on is proven before a single line of infrastructure exists. The same
 * scenario is later run end to end through the handlers against MySQL.
 */
final class AssignmentScenarioTest extends TestCase
{
    public function test_the_worked_example_ends_at_the_expected_value(): void
    {
        $line = EarningLine::calculate(EarningLineId::generate(), Money::fromDecimalString('1000.00'));

        // Step 1 -- system calculates the line.
        self::assertSame('$1,000.00', $line->currentValue()->format());

        // Step 2 -- source data changes; no manual correction yet, so this is allowed.
        self::assertSame(
            RecalculationResult::Applied,
            $line->recalculate(Money::fromDecimalString('1050.00')),
        );
        self::assertSame('$1,050.00', $line->currentValue()->format());

        // Step 3 -- first manual correction. This freezes $1,050.00 for good.
        $line->addManualAdjustment(
            Money::fromDecimalString('-45.55'),
            AdjustmentComment::fromString('Employee declined dental benefit; reversing deduction'),
        );
        self::assertSame('$1,004.45', $line->currentValue()->format());

        // Step 4 -- source data changes again; the attempt must be ignored.
        self::assertSame(
            RecalculationResult::IgnoredBecauseFrozen,
            $line->recalculate(Money::fromDecimalString('1020.00')),
        );
        self::assertSame('$1,004.45', $line->currentValue()->format());

        // Step 5 -- second correction.
        $line->addManualAdjustment(
            Money::fromDecimalString('100.10'),
            AdjustmentComment::fromString('Late correction: missed approved overtime bonus'),
        );
        self::assertSame('$1,104.55', $line->currentValue()->format());

        // Step 6 -- third correction.
        $line->addManualAdjustment(
            Money::fromDecimalString('-0.10'),
            AdjustmentComment::fromString('Minor rounding adjustment'),
        );
        self::assertSame('$1,104.45', $line->currentValue()->format());

        // Step 7 -- fourth correction, which turns out to be a mistake.
        $line->addManualAdjustment(
            Money::fromDecimalString('-0.20'),
            AdjustmentComment::fromString('Second minor rounding adjustment'),
        );
        self::assertSame('$1,104.25', $line->currentValue()->format());

        // Step 8 -- compensating correction. The mistake is not edited away.
        $line->addManualAdjustment(
            Money::fromDecimalString('0.20'),
            AdjustmentComment::fromString('Correcting mistake in adjustment #4'),
        );

        self::assertSame('$1,104.45', $line->currentValue()->format());
    }

    public function test_the_final_audit_figures_match_the_assignment(): void
    {
        $line = self::runScenario();

        self::assertSame('$1,050.00', $line->systemValue()->format(), 'System value frozen at step 3');
        self::assertSame('$54.45', $line->adjustmentsTotal()->format());
        self::assertSame('$1,104.45', $line->currentValue()->format(), 'Current (new) value');
        self::assertTrue($line->isFrozen());
    }

    public function test_the_stream_records_exactly_what_happened(): void
    {
        $events = self::runScenario()->pendingEvents();

        $shape = array_map(
            static fn (DomainEvent $event): string => match (true) {
                $event instanceof EarningLineCalculated => 'calculated '.$event->systemValue->format(),
                $event instanceof SystemValueRecalculated => 'recalculated '.$event->systemValue->format(),
                $event instanceof SystemValueFrozen => 'frozen '.$event->frozenValue->format(),
                $event instanceof ManualAdjustmentAdded => 'adjusted '.$event->amount->format(),
                default => 'unknown',
            },
            $events,
        );

        // The ignored recalculation of step 4 leaves no trace: it changed nothing.
        self::assertSame([
            'calculated $1,000.00',
            'recalculated $1,050.00',
            'frozen $1,050.00',
            'adjusted -$45.55',
            'adjusted $100.10',
            'adjusted -$0.10',
            'adjusted -$0.20',
            'adjusted $0.20',
        ], $shape);
    }

    public function test_the_compensating_comment_points_at_the_adjustment_it_corrects(): void
    {
        $adjustments = array_values(array_filter(
            self::runScenario()->pendingEvents(),
            static fn (DomainEvent $event): bool => $event instanceof ManualAdjustmentAdded,
        ));

        // Step 8 says "Correcting mistake in adjustment #4", and #4 is the -$0.20
        // entry that #5 cancels out. Both stay in the history.
        self::assertSame('Correcting mistake in adjustment #4', $adjustments[4]->comment);
        self::assertSame('-$0.20', $adjustments[3]->amount->format());
        self::assertTrue($adjustments[3]->amount->add($adjustments[4]->amount)->isZero());
    }

    private static function runScenario(): EarningLine
    {
        $line = EarningLine::calculate(EarningLineId::generate(), Money::fromDecimalString('1000.00'));
        (void) $line->recalculate(Money::fromDecimalString('1050.00'));

        $line->addManualAdjustment(
            Money::fromDecimalString('-45.55'),
            AdjustmentComment::fromString('Employee declined dental benefit; reversing deduction'),
        );

        (void) $line->recalculate(Money::fromDecimalString('1020.00'));

        $line->addManualAdjustment(
            Money::fromDecimalString('100.10'),
            AdjustmentComment::fromString('Late correction: missed approved overtime bonus'),
        );
        $line->addManualAdjustment(
            Money::fromDecimalString('-0.10'),
            AdjustmentComment::fromString('Minor rounding adjustment'),
        );
        $line->addManualAdjustment(
            Money::fromDecimalString('-0.20'),
            AdjustmentComment::fromString('Second minor rounding adjustment'),
        );
        $line->addManualAdjustment(
            Money::fromDecimalString('0.20'),
            AdjustmentComment::fromString('Correcting mistake in adjustment #4'),
        );

        return $line;
    }
}
