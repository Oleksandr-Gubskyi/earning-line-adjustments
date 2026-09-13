<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Event;

use Payroll\Domain\Money;

/**
 * The system calculated the line for the first time. Always the first event in a stream.
 */
final readonly class EarningLineCalculated implements DomainEvent
{
    public function __construct(public Money $systemValue) {}
}
