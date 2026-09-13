<?php

declare(strict_types=1);

namespace Payroll\Application\Query;

use Payroll\Application\Exception\EarningLineNotFound;
use Payroll\Application\Port\EventStore;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\ManualAdjustmentAdded;
use Payroll\Domain\EarningLine\Event\SystemValueFrozen;
use Payroll\Domain\EarningLine\Event\SystemValueRecalculated;
use Payroll\Domain\Money;

/**
 * Builds the audit history straight from the event stream.
 *
 * The frozen base value is read from the SystemValueFrozen event rather than being
 * worked out here. Without that event this query would have to reimplement the
 * freeze rule -- "the last recalculation before the first adjustment" -- and the
 * most important rule in this domain would live in two places at once.
 *
 * It does fold the running total itself, which is the honest cost of a read side:
 * the same arithmetic exists in the aggregate. The scenario test asserts that both
 * arrive at the same number, so the two cannot drift unnoticed.
 *
 * No interface: there is one implementation and no second consumer, and an
 * interface on its own would be ceremony rather than separation.
 */
final readonly class EarningLineAuditHistory
{
    public function __construct(private EventStore $events) {}

    public function forLine(EarningLineId $id): AuditHistoryView
    {
        $stream = $this->events->load($id);

        if ($stream->isEmpty()) {
            throw EarningLineNotFound::withId($id->toString());
        }

        $systemValue = Money::zero();
        $adjustmentsTotal = Money::zero();
        $isFrozen = false;
        $adjustments = [];

        foreach ($stream->events as $recorded) {
            $event = $recorded->event;

            if ($event instanceof EarningLineCalculated) {
                $systemValue = $event->systemValue;
            } elseif ($event instanceof SystemValueRecalculated) {
                $systemValue = $event->systemValue;
            } elseif ($event instanceof SystemValueFrozen) {
                $systemValue = $event->frozenValue;
                $isFrozen = true;
            } elseif ($event instanceof ManualAdjustmentAdded) {
                $adjustmentsTotal = $adjustmentsTotal->add($event->amount);

                $adjustments[] = new AdjustmentEntry(
                    count($adjustments) + 1,
                    $event->amount,
                    $event->comment,
                    $systemValue->add($adjustmentsTotal),
                    $recorded->recordedAt,
                );
            }
        }

        return new AuditHistoryView(
            $id->toString(),
            $isFrozen,
            $systemValue,
            $adjustments,
            $adjustmentsTotal,
            $systemValue->add($adjustmentsTotal),
        );
    }
}
