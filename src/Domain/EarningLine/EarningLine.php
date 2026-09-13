<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine;

use LogicException;
use Payroll\Domain\EarningLine\Event\DomainEvent;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\ManualAdjustmentAdded;
use Payroll\Domain\EarningLine\Event\SystemValueFrozen;
use Payroll\Domain\EarningLine\Event\SystemValueRecalculated;
use Payroll\Domain\EarningLine\Exception\AdjustmentAmountMustNotBeZero;
use Payroll\Domain\EarningLine\Exception\EarningLineStreamIsEmpty;
use Payroll\Domain\Money;

/**
 * A payroll earning line: a system-calculated value that a specialist may correct.
 *
 * The rule that shapes everything here: the first manual adjustment permanently
 * freezes the system value, and automatic recalculation can never change the line
 * again. The freeze is irreversible because no method to undo it exists.
 *
 * The aggregate holds only the state it needs to make the next decision. It does
 * NOT hold the list of adjustments -- the immutable history is the stream of
 * ManualAdjustmentAdded events, and the read side rebuilds amounts, comments and
 * ordering from there. A collection here would be a second representation of the
 * same truth sitting next to the stream, and two representations drift.
 *
 * State changes happen only inside the apply* methods. Command methods check
 * invariants and record an event; they never touch a field directly. That is what
 * keeps a live aggregate and one rebuilt from its stream identical.
 */
final class EarningLine
{
    private Money $systemValue;

    private bool $frozen = false;

    private Money $adjustmentsTotal;

    private int $version = 0;

    /** @var list<DomainEvent> */
    private array $pendingEvents = [];

    private function __construct(public readonly EarningLineId $id) {}

    /**
     * Creates a line from the system's initial calculation.
     *
     * This is the only way a line comes into existence. The constructor is private,
     * so a second EarningLineCalculated cannot be appended to an existing stream --
     * which would otherwise silently overwrite the system value during replay, even
     * after the line had been frozen.
     */
    public static function calculate(EarningLineId $id, Money $systemValue): self
    {
        $line = new self($id);
        $line->recordThat(new EarningLineCalculated($systemValue));

        return $line;
    }

    /**
     * Rebuilds a line from its recorded history.
     *
     * Applies events directly and never records them, otherwise replayed history
     * would land in the pending buffer and be written to the stream a second time.
     *
     * @param  list<DomainEvent>  $events
     */
    public static function reconstitute(EarningLineId $id, array $events, int $version): self
    {
        if ($events === []) {
            throw EarningLineStreamIsEmpty::forId($id->toString());
        }

        $line = new self($id);

        foreach ($events as $event) {
            $line->apply($event);
        }

        $line->version = $version;

        return $line;
    }

    /**
     * Applies a fresh system calculation, unless the line has been frozen.
     *
     * Being ignored is a documented outcome rather than an error, and the decision
     * is made here: if a handler inspected the freeze state instead, the central
     * rule of this domain would have leaked out of the domain.
     */
    public function recalculate(Money $systemValue): RecalculationResult
    {
        if ($this->frozen) {
            return RecalculationResult::IgnoredBecauseFrozen;
        }

        $this->recordThat(new SystemValueRecalculated($systemValue));

        return RecalculationResult::Applied;
    }

    /**
     * Records a manual correction, freezing the system value if this is the first one.
     *
     * Both events are recorded together and the repository writes them in a single
     * append, so a stream can never contain an adjustment without its freeze.
     */
    public function addManualAdjustment(Money $amount, AdjustmentComment $comment): void
    {
        if ($amount->isZero()) {
            throw AdjustmentAmountMustNotBeZero::create();
        }

        if (! $this->frozen) {
            $this->recordThat(new SystemValueFrozen($this->systemValue));
        }

        $this->recordThat(new ManualAdjustmentAdded($amount, $comment->toString()));
    }

    /**
     * Always derived, never stored: the frozen or live system value plus every adjustment.
     */
    public function currentValue(): Money
    {
        return $this->systemValue->add($this->adjustmentsTotal);
    }

    public function systemValue(): Money
    {
        return $this->systemValue;
    }

    public function adjustmentsTotal(): Money
    {
        return $this->adjustmentsTotal;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * A line is frozen exactly when it has received its first adjustment, so these
     * are the same fact stated in two business vocabularies.
     */
    public function hasManualAdjustments(): bool
    {
        return $this->frozen;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Events recorded but not yet written to the store. Reading does not consume
     * them: they are dropped by commit(), and only after the append succeeded.
     *
     * @return list<DomainEvent>
     */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    /**
     * Acknowledges that the pending events are now part of the stream.
     */
    public function commit(): void
    {
        $this->version += count($this->pendingEvents);
        $this->pendingEvents = [];
    }

    private function recordThat(DomainEvent $event): void
    {
        $this->apply($event);
        $this->pendingEvents[] = $event;
    }

    private function apply(DomainEvent $event): void
    {
        // An unknown event type fails loudly rather than being skipped: a silently
        // ignored event means a wrong current value, which is the worst outcome here.
        match (true) {
            $event instanceof EarningLineCalculated => $this->applyEarningLineCalculated($event),
            $event instanceof SystemValueRecalculated => $this->applySystemValueRecalculated($event),
            $event instanceof SystemValueFrozen => $this->applySystemValueFrozen($event),
            $event instanceof ManualAdjustmentAdded => $this->applyManualAdjustmentAdded($event),
            default => throw new LogicException(sprintf('EarningLine cannot apply %s.', $event::class)),
        };
    }

    private function applyEarningLineCalculated(EarningLineCalculated $event): void
    {
        $this->systemValue = $event->systemValue;
        $this->adjustmentsTotal = Money::zero();
        $this->frozen = false;
    }

    private function applySystemValueRecalculated(SystemValueRecalculated $event): void
    {
        $this->systemValue = $event->systemValue;
    }

    private function applySystemValueFrozen(SystemValueFrozen $event): void
    {
        // The frozen amount comes from the event rather than from current state, so
        // a rebuilt line does not depend on having replayed the recalculations first.
        $this->systemValue = $event->frozenValue;
        $this->frozen = true;
    }

    private function applyManualAdjustmentAdded(ManualAdjustmentAdded $event): void
    {
        $this->adjustmentsTotal = $this->adjustmentsTotal->add($event->amount);
    }
}
