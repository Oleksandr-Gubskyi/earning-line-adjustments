<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Payroll\Application\Exception\EarningLineNotFound;
use Payroll\Application\Query\AuditHistoryView;
use Payroll\Application\Query\EarningLineAuditHistory;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Exception\InvalidEarningLineId;

/**
 * Reads a line's audit history back from the durable stream.
 *
 * The only consumer of the audit query outside the demo, and the reason the read
 * side has an entry point of its own rather than being a detail of one command.
 */
final class PayrollShowCommand extends Command
{
    protected $signature = 'payroll:show {lineId : The earning line to inspect}';

    protected $description = 'Show the current value and full audit history of an earning line';

    public function handle(EarningLineAuditHistory $history): int
    {
        $lineId = $this->argument('lineId');

        if (! is_string($lineId)) {
            $this->components->error('An earning line id is required.');

            return self::FAILURE;
        }

        try {
            $view = $history->forLine(EarningLineId::fromString($lineId));
        } catch (InvalidEarningLineId|EarningLineNotFound $e) {
            // A mistyped id is a user error, not a stack trace.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderAuditHistory($view);

        return self::SUCCESS;
    }

    public function renderAuditHistory(AuditHistoryView $view): void
    {
        $this->newLine();
        $this->line('  <options=bold>Audit history</> <fg=gray>'.$view->lineId.'</>');
        $this->newLine();

        $rows = [[
            $view->isFrozen ? 'System value (frozen)' : 'System value (not frozen yet)',
            '',
            $this->amount($view->systemValue->format()),
            '',
        ]];

        foreach ($view->adjustments as $entry) {
            $rows[] = [
                'Adjustment #'.$entry->number,
                $this->amount($entry->amount->format()),
                $this->amount($entry->valueAfter->format()),
                $entry->comment,
            ];
        }

        $rows[] = ['<options=bold>Current value</>', '', '<options=bold>'.$view->currentValue->format().'</>', ''];

        $this->table(['Entry', 'Amount', 'Value', 'Comment'], $rows);
    }

    private function amount(string $formatted): string
    {
        return str_starts_with($formatted, '-')
            ? '<fg=red>'.$formatted.'</>'
            : '<fg=green>'.$formatted.'</>';
    }
}
