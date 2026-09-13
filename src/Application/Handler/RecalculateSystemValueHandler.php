<?php

declare(strict_types=1);

namespace Payroll\Application\Handler;

use Payroll\Application\Command\RecalculateSystemValue;
use Payroll\Application\Port\EarningLineRepository;
use Payroll\Domain\EarningLine\RecalculationResult;

final readonly class RecalculateSystemValueHandler
{
    public function __construct(private EarningLineRepository $lines) {}

    /**
     * Returns the aggregate's own verdict. The handler never inspects the freeze
     * state itself -- that decision belongs to the line, and asking here would be
     * the first step towards the rule living in two places.
     */
    public function __invoke(RecalculateSystemValue $command): RecalculationResult
    {
        $line = $this->lines->get($command->id);

        $result = $line->recalculate($command->systemValue);

        // A no-op save when the recalculation was ignored: nothing was recorded.
        $this->lines->save($line);

        return $result;
    }
}
