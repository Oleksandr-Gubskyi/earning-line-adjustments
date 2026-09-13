<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Payroll\Application\Command\AddManualAdjustment;
use Payroll\Application\Command\CalculateEarningLine;
use Payroll\Application\Command\RecalculateSystemValue;
use Payroll\Application\Handler\AddManualAdjustmentHandler;
use Payroll\Application\Handler\CalculateEarningLineHandler;
use Payroll\Application\Handler\RecalculateSystemValueHandler;
use Payroll\Application\Port\EarningLineRepository;
use Payroll\Domain\EarningLine\AdjustmentComment;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\RecalculationResult;
use Payroll\Domain\Money;

/**
 * Replays the worked example from the assignment against the real database.
 *
 * It goes through the actual command handlers and the actual event store, so the
 * output is earned rather than printed from a script, and it verifies itself
 * against the expected final value before exiting.
 */
final class PayrollDemoCommand extends Command
{
    private const EXPECTED_FINAL_VALUE = '$1,104.45';

    protected $signature = 'payroll:demo';

    protected $description = 'Run the assignment scenario end to end and show the resulting audit history';

    /**
     * @var list<array{string, string, string, string}>
     */
    private array $walkthrough = [];

    public function handle(
        EarningLineRepository $lines,
        CalculateEarningLineHandler $calculate,
        RecalculateSystemValueHandler $recalculate,
        AddManualAdjustmentHandler $adjust,
    ): int {
        // A fresh id every run, so the demo can be run twice without colliding.
        $id = EarningLineId::generate();

        $this->newLine();
        $this->line('  <options=bold>Scenario</> <fg=gray>'.$id->toString().'</>');
        $this->newLine();

        $calculate(new CalculateEarningLine($id, Money::fromDecimalString('1000.00')));
        $this->record(1, 'System calculates the line', '', $lines, $id);

        $this->recalculateStep(2, $recalculate, $lines, $id, '1050.00', 'Source data changes, system recalculates');

        $this->adjustStep(3, $adjust, $lines, $id, '-45.55', 'Employee declined dental benefit; reversing deduction');

        $this->recalculateStep(4, $recalculate, $lines, $id, '1020.00', 'Source data changes again, system attempts to recalculate');

        $this->adjustStep(5, $adjust, $lines, $id, '100.10', 'Late correction: missed approved overtime bonus');
        $this->adjustStep(6, $adjust, $lines, $id, '-0.10', 'Minor rounding adjustment');
        $this->adjustStep(7, $adjust, $lines, $id, '-0.20', 'Second minor rounding adjustment');
        $this->adjustStep(8, $adjust, $lines, $id, '0.20', 'Correcting mistake in adjustment #4');

        $this->table(['Step', 'Event', 'Amount', 'Current value after this step'], $this->walkthrough);

        $this->call('payroll:show', ['lineId' => $id->toString()]);

        return $this->verify($lines->get($id)->currentValue()->format(), $id);
    }

    private function recalculateStep(
        int $step,
        RecalculateSystemValueHandler $recalculate,
        EarningLineRepository $lines,
        EarningLineId $id,
        string $amount,
        string $description,
    ): void {
        $result = $recalculate(new RecalculateSystemValue($id, Money::fromDecimalString($amount)));

        $note = $result === RecalculationResult::IgnoredBecauseFrozen
            ? $description.' <fg=yellow>(IGNORED: the line already has a manual correction)</>'
            : $description;

        $this->record($step, $note, '', $lines, $id);
    }

    private function adjustStep(
        int $step,
        AddManualAdjustmentHandler $adjust,
        EarningLineRepository $lines,
        EarningLineId $id,
        string $amount,
        string $comment,
    ): void {
        $money = Money::fromDecimalString($amount);

        $adjust(new AddManualAdjustment($id, $money, AdjustmentComment::fromString($comment)));

        $this->record($step, 'Specialist adds a manual correction', $money->format(), $lines, $id);
    }

    private function record(
        int $step,
        string $event,
        string $amount,
        EarningLineRepository $lines,
        EarningLineId $id,
    ): void {
        // Read back through the repository, so every row shown is the persisted
        // value rather than something held in memory by this command.
        $this->walkthrough[] = [
            (string) $step,
            $event,
            $amount,
            $lines->get($id)->currentValue()->format(),
        ];
    }

    private function verify(string $actual, EarningLineId $id): int
    {
        $this->newLine();

        if ($actual !== self::EXPECTED_FINAL_VALUE) {
            $this->line(sprintf(
                '  <fg=red;options=bold>FAILED</> Expected %s but the line is at %s.',
                self::EXPECTED_FINAL_VALUE,
                $actual,
            ));

            return self::FAILURE;
        }

        // Plain output rather than $this->components->info(), which wraps to the
        // terminal width and would split the sentence a test looks for.
        $this->line(sprintf('  <fg=green;options=bold>OK</> Final value %s matches the assignment.', $actual));
        $this->line('  Inspect it again with: <options=bold>php artisan payroll:show '.$id->toString().'</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
