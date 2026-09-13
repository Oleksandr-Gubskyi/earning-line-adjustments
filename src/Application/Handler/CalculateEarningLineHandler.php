<?php

declare(strict_types=1);

namespace Payroll\Application\Handler;

use Payroll\Application\Command\CalculateEarningLine;
use Payroll\Application\Port\EarningLineRepository;
use Payroll\Domain\EarningLine\EarningLine;

final readonly class CalculateEarningLineHandler
{
    public function __construct(private EarningLineRepository $lines) {}

    public function __invoke(CalculateEarningLine $command): void
    {
        $this->lines->save(
            EarningLine::calculate($command->id, $command->systemValue),
        );
    }
}
