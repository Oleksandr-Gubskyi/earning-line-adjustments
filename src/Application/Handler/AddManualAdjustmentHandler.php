<?php

declare(strict_types=1);

namespace Payroll\Application\Handler;

use Payroll\Application\Command\AddManualAdjustment;
use Payroll\Application\Port\EarningLineRepository;

final readonly class AddManualAdjustmentHandler
{
    public function __construct(private EarningLineRepository $lines) {}

    public function __invoke(AddManualAdjustment $command): void
    {
        $line = $this->lines->get($command->id);

        $line->addManualAdjustment($command->amount, $command->comment);

        $this->lines->save($line);
    }
}
