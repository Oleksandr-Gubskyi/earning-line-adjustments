<?php

declare(strict_types=1);

namespace Payroll\Application\Port;

use Payroll\Application\Exception\ConcurrencyConflict;
use Payroll\Application\Exception\EarningLineNotFound;
use Payroll\Domain\EarningLine\EarningLine;
use Payroll\Domain\EarningLine\EarningLineId;

interface EarningLineRepository
{
    /**
     * @throws EarningLineNotFound
     */
    public function get(EarningLineId $id): EarningLine;

    /**
     * Writes whatever the line has recorded since it was loaded, then marks it committed.
     *
     * @throws ConcurrencyConflict
     */
    public function save(EarningLine $line): void;
}
