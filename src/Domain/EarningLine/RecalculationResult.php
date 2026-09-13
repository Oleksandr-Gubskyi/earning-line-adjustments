<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine;

/**
 * The outcome of asking the system to recalculate a line.
 *
 * Being ignored is a normal outcome, not a failure: the assignment requires a
 * post-freeze recalculation to have no effect, and callers are told so rather
 * than having an exception thrown at them.
 *
 * The aggregate decides this, never a handler. If a handler checked the freeze
 * state itself, the central rule of this domain would have left the domain.
 */
enum RecalculationResult
{
    case Applied;
    case IgnoredBecauseFrozen;
}
