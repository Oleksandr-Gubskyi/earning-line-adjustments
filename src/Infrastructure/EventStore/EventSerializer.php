<?php

declare(strict_types=1);

namespace Payroll\Infrastructure\EventStore;

use Payroll\Domain\EarningLine\Event\DomainEvent;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\ManualAdjustmentAdded;
use Payroll\Domain\EarningLine\Event\SystemValueFrozen;
use Payroll\Domain\EarningLine\Event\SystemValueRecalculated;
use Payroll\Domain\Money;
use Payroll\Infrastructure\EventStore\Exception\EventSerializationFailed;

/**
 * Translates domain events to and from what the store actually holds.
 *
 * Stored types are logical names, never class names. A stored FQCN would mean that
 * renaming or moving a class silently breaks the reading of history that was
 * already written, turning a refactor into a data migration.
 *
 * This is the only schema evolution problem solved here. Adding, retyping or
 * removing a payload field is not handled -- upcasting is deliberately out of
 * scope for a proof of concept.
 */
final class EventSerializer
{
    private const CALCULATED = 'earning_line.calculated';

    private const RECALCULATED = 'earning_line.system_value_recalculated';

    private const FROZEN = 'earning_line.system_value_frozen';

    private const ADJUSTMENT_ADDED = 'earning_line.manual_adjustment_added';

    /**
     * Stored type name for every event this store can write.
     *
     * @return array<string, class-string<DomainEvent>>
     */
    public static function knownTypes(): array
    {
        return [
            self::CALCULATED => EarningLineCalculated::class,
            self::RECALCULATED => SystemValueRecalculated::class,
            self::FROZEN => SystemValueFrozen::class,
            self::ADJUSTMENT_ADDED => ManualAdjustmentAdded::class,
        ];
    }

    /**
     * @return array{type: string, payload: string}
     */
    public function serialize(DomainEvent $event): array
    {
        [$type, $payload] = match (true) {
            $event instanceof EarningLineCalculated => [
                self::CALCULATED,
                ['system_value_minor_units' => $event->systemValue->minorUnits],
            ],
            $event instanceof SystemValueRecalculated => [
                self::RECALCULATED,
                ['system_value_minor_units' => $event->systemValue->minorUnits],
            ],
            $event instanceof SystemValueFrozen => [
                self::FROZEN,
                ['frozen_value_minor_units' => $event->frozenValue->minorUnits],
            ],
            $event instanceof ManualAdjustmentAdded => [
                self::ADJUSTMENT_ADDED,
                [
                    'amount_minor_units' => $event->amount->minorUnits,
                    'comment' => $event->comment,
                ],
            ],
            default => throw EventSerializationFailed::unknownType($event::class),
        };

        return [
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    public function deserialize(string $type, string $payload): DomainEvent
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw EventSerializationFailed::payloadIsNotAnObject($type);
        }

        return match ($type) {
            self::CALCULATED => new EarningLineCalculated(
                Money::fromMinorUnits(self::intField($decoded, 'system_value_minor_units', $type)),
            ),
            self::RECALCULATED => new SystemValueRecalculated(
                Money::fromMinorUnits(self::intField($decoded, 'system_value_minor_units', $type)),
            ),
            self::FROZEN => new SystemValueFrozen(
                Money::fromMinorUnits(self::intField($decoded, 'frozen_value_minor_units', $type)),
            ),
            self::ADJUSTMENT_ADDED => new ManualAdjustmentAdded(
                Money::fromMinorUnits(self::intField($decoded, 'amount_minor_units', $type)),
                self::stringField($decoded, 'comment', $type),
            ),
            default => throw EventSerializationFailed::unknownType($type),
        };
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function intField(array $payload, string $field, string $type): int
    {
        $value = $payload[$field] ?? null;

        // Strict: a float here would mean a rounded amount, which is the one thing
        // this domain must never do silently.
        if (! is_int($value)) {
            throw EventSerializationFailed::missingField($type, $field, 'an integer');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function stringField(array $payload, string $field, string $type): string
    {
        $value = $payload[$field] ?? null;

        if (! is_string($value)) {
            throw EventSerializationFailed::missingField($type, $field, 'a string');
        }

        return $value;
    }
}
