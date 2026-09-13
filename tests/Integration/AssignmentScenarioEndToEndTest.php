<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Payroll\Application\Command\AddManualAdjustment;
use Payroll\Application\Command\CalculateEarningLine;
use Payroll\Application\Command\RecalculateSystemValue;
use Payroll\Application\Handler\AddManualAdjustmentHandler;
use Payroll\Application\Handler\CalculateEarningLineHandler;
use Payroll\Application\Handler\RecalculateSystemValueHandler;
use Payroll\Application\Port\EarningLineRepository;
use Payroll\Application\Query\AdjustmentEntry;
use Payroll\Application\Query\EarningLineAuditHistory;
use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\RecalculationResult;
use Payroll\Domain\Money;
use Tests\TestCase;

/**
 * The assignment scenario through the real handlers, the real repository and MySQL.
 *
 * The aggregate-level version of this already passes without any infrastructure.
 * This one proves the same thing survives serialization, the database and a
 * rebuild from the persisted stream.
 */
final class AssignmentScenarioEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_worked_example_ends_at_the_expected_value(): void
    {
        $id = $this->runScenario();

        $line = $this->app->make(EarningLineRepository::class)->get($id);

        self::assertSame('$1,104.45', $line->currentValue()->format());
        self::assertSame('$1,050.00', $line->systemValue()->format());
        self::assertTrue($line->isFrozen());
    }

    public function test_the_audit_history_matches_the_assignment(): void
    {
        $view = $this->app->make(EarningLineAuditHistory::class)->forLine($this->runScenario());

        self::assertSame('$1,050.00', $view->systemValue->format());
        self::assertTrue($view->isFrozen);
        self::assertSame(
            ['-$45.55', '$100.10', '-$0.10', '-$0.20', '$0.20'],
            array_map(static fn (AdjustmentEntry $e): string => $e->amount->format(), $view->adjustments),
        );
        self::assertSame([1, 2, 3, 4, 5], array_map(
            static fn (AdjustmentEntry $e): int => $e->number,
            $view->adjustments,
        ));
        self::assertSame('$1,104.45', $view->currentValue->format());
    }

    public function test_the_stream_holds_exactly_eight_events(): void
    {
        $id = $this->runScenario();

        $types = $this->app->make('db')->connection()
            ->table('domain_events')
            ->where('stream_id', $id->toString())
            ->orderBy('stream_version')
            ->pluck('event_type')
            ->all();

        // The ignored recalculation of step 4 is not among them.
        self::assertSame([
            'earning_line.calculated',
            'earning_line.system_value_recalculated',
            'earning_line.system_value_frozen',
            'earning_line.manual_adjustment_added',
            'earning_line.manual_adjustment_added',
            'earning_line.manual_adjustment_added',
            'earning_line.manual_adjustment_added',
            'earning_line.manual_adjustment_added',
        ], $types);
    }

    public function test_a_rebuild_from_the_database_agrees_with_the_read_model(): void
    {
        $id = $this->runScenario();

        $fromAggregate = $this->app->make(EarningLineRepository::class)->get($id)->currentValue();
        $fromReadModel = $this->app->make(EarningLineAuditHistory::class)->forLine($id)->currentValue;

        self::assertSame($fromAggregate->minorUnits, $fromReadModel->minorUnits);
        self::assertSame(110445, $fromAggregate->minorUnits);
    }

    private function runScenario(): EarningLineId
    {
        $id = EarningLineId::generate();

        $this->app->make(CalculateEarningLineHandler::class)(
            new CalculateEarningLine($id, Money::fromDecimalString('1000.00')),
        );

        $recalculate = $this->app->make(RecalculateSystemValueHandler::class);

        self::assertSame(
            RecalculationResult::Applied,
            $recalculate(new RecalculateSystemValue($id, Money::fromDecimalString('1050.00'))),
        );

        $this->adjust($id, '-45.55', 'Employee declined dental benefit; reversing deduction');

        self::assertSame(
            RecalculationResult::IgnoredBecauseFrozen,
            $recalculate(new RecalculateSystemValue($id, Money::fromDecimalString('1020.00'))),
        );

        $this->adjust($id, '100.10', 'Late correction: missed approved overtime bonus');
        $this->adjust($id, '-0.10', 'Minor rounding adjustment');
        $this->adjust($id, '-0.20', 'Second minor rounding adjustment');
        $this->adjust($id, '0.20', 'Correcting mistake in adjustment #4');

        return $id;
    }

    private function adjust(EarningLineId $id, string $amount, string $comment): void
    {
        $this->app->make(AddManualAdjustmentHandler::class)(
            new AddManualAdjustment(
                $id,
                Money::fromDecimalString($amount),
                AdjustmentComment::fromString($comment),
            ),
        );
    }
}
