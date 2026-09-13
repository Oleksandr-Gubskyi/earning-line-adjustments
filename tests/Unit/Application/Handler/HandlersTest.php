<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Handler;

use Payroll\Application\Command\AddManualAdjustment;
use Payroll\Application\Command\CalculateEarningLine;
use Payroll\Application\Command\RecalculateSystemValue;
use Payroll\Application\Exception\EarningLineNotFound;
use Payroll\Application\Handler\AddManualAdjustmentHandler;
use Payroll\Application\Handler\CalculateEarningLineHandler;
use Payroll\Application\Handler\RecalculateSystemValueHandler;
use Payroll\Application\Port\EarningLineRepository;
use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\RecalculationResult;
use Payroll\Domain\Money;
use Payroll\Infrastructure\EventStore\InMemoryEventStore;
use Payroll\Infrastructure\Repository\EventSourcedEarningLineRepository;
use PHPUnit\Framework\TestCase;

final class HandlersTest extends TestCase
{
    private EarningLineRepository $lines;

    protected function setUp(): void
    {
        $this->lines = new EventSourcedEarningLineRepository(new InMemoryEventStore);
    }

    public function test_it_calculates_a_new_line(): void
    {
        $id = EarningLineId::generate();

        (new CalculateEarningLineHandler($this->lines))(
            new CalculateEarningLine($id, Money::fromDecimalString('1000.00')),
        );

        self::assertSame('$1,000.00', $this->lines->get($id)->currentValue()->format());
    }

    public function test_it_recalculates_an_open_line(): void
    {
        $id = $this->givenACalculatedLine();

        $result = (new RecalculateSystemValueHandler($this->lines))(
            new RecalculateSystemValue($id, Money::fromDecimalString('1050.00')),
        );

        self::assertSame(RecalculationResult::Applied, $result);
        self::assertSame('$1,050.00', $this->lines->get($id)->currentValue()->format());
    }

    public function test_it_reports_a_recalculation_ignored_after_the_freeze(): void
    {
        $id = $this->givenACalculatedLine();
        $this->givenAnAdjustment($id, '-45.55');

        $result = (new RecalculateSystemValueHandler($this->lines))(
            new RecalculateSystemValue($id, Money::fromDecimalString('1020.00')),
        );

        // Not an exception: the specification asks for the attempt to be ignored,
        // and the caller is told so.
        self::assertSame(RecalculationResult::IgnoredBecauseFrozen, $result);
        self::assertSame('$954.45', $this->lines->get($id)->currentValue()->format());
    }

    public function test_an_ignored_recalculation_leaves_the_stream_alone(): void
    {
        $id = $this->givenACalculatedLine();
        $this->givenAnAdjustment($id, '-45.55');
        $versionBefore = $this->lines->get($id)->version();

        (void) (new RecalculateSystemValueHandler($this->lines))(
            new RecalculateSystemValue($id, Money::fromDecimalString('1020.00')),
        );

        self::assertSame($versionBefore, $this->lines->get($id)->version());
    }

    public function test_it_adds_a_manual_adjustment(): void
    {
        $id = $this->givenACalculatedLine();

        $this->givenAnAdjustment($id, '-45.55');
        $this->givenAnAdjustment($id, '100.10');

        $line = $this->lines->get($id);
        self::assertSame('$1,054.55', $line->currentValue()->format());
        self::assertTrue($line->isFrozen());
    }

    public function test_commands_against_a_missing_line_are_rejected(): void
    {
        $this->expectException(EarningLineNotFound::class);

        (new AddManualAdjustmentHandler($this->lines))(
            new AddManualAdjustment(
                EarningLineId::generate(),
                Money::fromDecimalString('1.00'),
                AdjustmentComment::fromString('Nothing to adjust'),
            ),
        );
    }

    public function test_recalculating_a_missing_line_is_rejected(): void
    {
        $this->expectException(EarningLineNotFound::class);

        (void) (new RecalculateSystemValueHandler($this->lines))(
            new RecalculateSystemValue(EarningLineId::generate(), Money::fromDecimalString('1.00')),
        );
    }

    private function givenACalculatedLine(): EarningLineId
    {
        $id = EarningLineId::generate();

        (new CalculateEarningLineHandler($this->lines))(
            new CalculateEarningLine($id, Money::fromDecimalString('1000.00')),
        );

        return $id;
    }

    private function givenAnAdjustment(EarningLineId $id, string $amount): void
    {
        (new AddManualAdjustmentHandler($this->lines))(
            new AddManualAdjustment(
                $id,
                Money::fromDecimalString($amount),
                AdjustmentComment::fromString('Adjustment'),
            ),
        );
    }
}
