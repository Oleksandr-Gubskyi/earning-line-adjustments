<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Query;

use Payroll\Application\Command\AddManualAdjustment;
use Payroll\Application\Command\CalculateEarningLine;
use Payroll\Application\Command\RecalculateSystemValue;
use Payroll\Application\Exception\EarningLineNotFound;
use Payroll\Application\Handler\AddManualAdjustmentHandler;
use Payroll\Application\Handler\CalculateEarningLineHandler;
use Payroll\Application\Handler\RecalculateSystemValueHandler;
use Payroll\Application\Port\EarningLineRepository;
use Payroll\Application\Query\AdjustmentEntry;
use Payroll\Application\Query\EarningLineAuditHistory;
use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\Money;
use Payroll\Infrastructure\EventStore\InMemoryEventStore;
use Payroll\Infrastructure\Repository\EventSourcedEarningLineRepository;
use PHPUnit\Framework\TestCase;

final class EarningLineAuditHistoryTest extends TestCase
{
    private InMemoryEventStore $store;

    private EarningLineRepository $lines;

    private EarningLineAuditHistory $history;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore;
        $this->lines = new EventSourcedEarningLineRepository($this->store);
        $this->history = new EarningLineAuditHistory($this->store);
    }

    public function test_an_unknown_line_has_no_history(): void
    {
        $this->expectException(EarningLineNotFound::class);

        $this->history->forLine(EarningLineId::generate());
    }

    public function test_an_open_line_shows_the_live_system_value(): void
    {
        $id = EarningLineId::generate();
        $this->calculate($id, '1000.00');
        $this->recalculate($id, '1050.00');

        $view = $this->history->forLine($id);

        self::assertFalse($view->isFrozen);
        self::assertSame('$1,050.00', $view->systemValue->format());
        self::assertSame([], $view->adjustments);
        self::assertSame('$1,050.00', $view->currentValue->format());
    }

    public function test_it_reproduces_the_expected_audit_history_from_the_assignment(): void
    {
        $view = $this->history->forLine($this->runAssignmentScenario());

        $rendered = ['System value (frozen)' => $view->systemValue->format()];

        foreach ($view->adjustments as $entry) {
            $rendered['Adjustment '.$entry->number] = $entry->amount->format();
        }

        $rendered['Current (new) value'] = $view->currentValue->format();

        self::assertSame([
            'System value (frozen)' => '$1,050.00',
            'Adjustment 1' => '-$45.55',
            'Adjustment 2' => '$100.10',
            'Adjustment 3' => '-$0.10',
            'Adjustment 4' => '-$0.20',
            'Adjustment 5' => '$0.20',
            'Current (new) value' => '$1,104.45',
        ], $rendered);
    }

    public function test_the_running_value_after_each_adjustment_matches_the_walkthrough(): void
    {
        $view = $this->history->forLine($this->runAssignmentScenario());

        self::assertSame(
            ['$1,004.45', '$1,104.55', '$1,104.45', '$1,104.25', '$1,104.45'],
            array_map(
                static fn (AdjustmentEntry $entry): string => $entry->valueAfter->format(),
                $view->adjustments,
            ),
        );
    }

    public function test_comments_are_part_of_the_history(): void
    {
        $view = $this->history->forLine($this->runAssignmentScenario());

        // "must stay visible and traceable" is not satisfied by amounts alone.
        self::assertSame(
            'Employee declined dental benefit; reversing deduction',
            $view->adjustments[0]->comment,
        );
        self::assertSame('Correcting mistake in adjustment #4', $view->adjustments[4]->comment);
    }

    public function test_the_read_model_agrees_with_the_aggregate(): void
    {
        $id = $this->runAssignmentScenario();

        // Both fold the same stream independently; this is what stops them drifting.
        self::assertSame(
            $this->lines->get($id)->currentValue()->minorUnits,
            $this->history->forLine($id)->currentValue->minorUnits,
        );
    }

    public function test_the_ignored_recalculation_is_absent_from_the_history(): void
    {
        $view = $this->history->forLine($this->runAssignmentScenario());

        // The assignment's expected table lists the frozen value and five
        // adjustments, and nothing else.
        self::assertCount(5, $view->adjustments);
        self::assertTrue($view->isFrozen);
        self::assertSame('$54.45', $view->adjustmentsTotal->format());
    }

    private function runAssignmentScenario(): EarningLineId
    {
        $id = EarningLineId::generate();

        $this->calculate($id, '1000.00');
        $this->recalculate($id, '1050.00');
        $this->adjust($id, '-45.55', 'Employee declined dental benefit; reversing deduction');
        $this->recalculate($id, '1020.00');
        $this->adjust($id, '100.10', 'Late correction: missed approved overtime bonus');
        $this->adjust($id, '-0.10', 'Minor rounding adjustment');
        $this->adjust($id, '-0.20', 'Second minor rounding adjustment');
        $this->adjust($id, '0.20', 'Correcting mistake in adjustment #4');

        return $id;
    }

    private function calculate(EarningLineId $id, string $amount): void
    {
        (new CalculateEarningLineHandler($this->lines))(
            new CalculateEarningLine($id, Money::fromDecimalString($amount)),
        );
    }

    private function recalculate(EarningLineId $id, string $amount): void
    {
        (void) (new RecalculateSystemValueHandler($this->lines))(
            new RecalculateSystemValue($id, Money::fromDecimalString($amount)),
        );
    }

    private function adjust(EarningLineId $id, string $amount, string $comment): void
    {
        (new AddManualAdjustmentHandler($this->lines))(
            new AddManualAdjustment(
                $id,
                Money::fromDecimalString($amount),
                AdjustmentComment::fromString($comment),
            ),
        );
    }
}
