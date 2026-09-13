<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Payroll\Domain\EarningLine\Event\DomainEvent;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\EarningLine\Event\ManualAdjustmentAdded;
use Payroll\Domain\EarningLine\Event\SystemValueFrozen;
use Payroll\Domain\EarningLine\Event\SystemValueRecalculated;
use Payroll\Domain\Money;
use Payroll\Infrastructure\EventStore\EventSerializer;
use Payroll\Infrastructure\EventStore\Exception\EventSerializationFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EventSerializerTest extends TestCase
{
    /**
     * Guards against the quiet failure of adding an event and forgetting the map:
     * it would only surface when something tried to read that event back.
     */
    public function test_every_domain_event_has_a_stored_type(): void
    {
        $registered = EventSerializer::knownTypes();

        foreach (self::allDomainEventClasses() as $class) {
            self::assertContains(
                $class,
                $registered,
                sprintf('%s is not registered in EventSerializer::knownTypes().', $class),
            );
        }

        self::assertCount(count(self::allDomainEventClasses()), $registered);
    }

    public function test_stored_type_names_are_logical_not_class_names(): void
    {
        foreach (array_keys(EventSerializer::knownTypes()) as $type) {
            // A stored FQCN would turn any rename into a data migration.
            self::assertStringStartsWith('earning_line.', $type);
            self::assertStringNotContainsString('\\', $type);
        }
    }

    #[DataProvider('everyEvent')]
    public function test_events_round_trip_unchanged(DomainEvent $event): void
    {
        $serializer = new EventSerializer;

        $stored = $serializer->serialize($event);
        $restored = $serializer->deserialize($stored['type'], $stored['payload']);

        self::assertEquals($event, $restored);
    }

    public static function everyEvent(): iterable
    {
        yield 'calculated' => [new EarningLineCalculated(Money::fromDecimalString('1000.00'))];
        yield 'recalculated' => [new SystemValueRecalculated(Money::fromDecimalString('1050.00'))];
        yield 'frozen' => [new SystemValueFrozen(Money::fromDecimalString('1050.00'))];
        yield 'adjustment' => [new ManualAdjustmentAdded(Money::fromMinorUnits(-4555), 'Reversing deduction')];
        yield 'adjustment with unicode' => [new ManualAdjustmentAdded(Money::fromMinorUnits(20), 'Zoë — 🙂')];
        yield 'adjustment with quotes' => [new ManualAdjustmentAdded(Money::fromMinorUnits(-1), 'He said "no"')];
    }

    public function test_the_payload_stores_minor_units_not_a_decimal(): void
    {
        $stored = (new EventSerializer)->serialize(
            new ManualAdjustmentAdded(Money::fromDecimalString('-45.55'), 'Reversing'),
        );

        $payload = json_decode($stored['payload'], true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertSame(-4555, $payload['amount_minor_units']);
    }

    public function test_an_unknown_stored_type_is_rejected(): void
    {
        $this->expectException(EventSerializationFailed::class);

        (new EventSerializer)->deserialize('earning_line.something_removed', '{}');
    }

    public function test_a_payload_missing_a_field_is_rejected(): void
    {
        $this->expectException(EventSerializationFailed::class);

        (new EventSerializer)->deserialize('earning_line.calculated', '{}');
    }

    public function test_a_decimal_amount_in_the_payload_is_rejected(): void
    {
        // A float here would mean someone rounded money on the way in.
        $this->expectException(EventSerializationFailed::class);

        (new EventSerializer)->deserialize(
            'earning_line.calculated',
            '{"system_value_minor_units": 1000.5}',
        );
    }

    public function test_a_payload_that_is_not_an_object_is_rejected(): void
    {
        $this->expectException(EventSerializationFailed::class);

        (new EventSerializer)->deserialize('earning_line.calculated', '"just a string"');
    }

    /**
     * @return list<class-string<DomainEvent>>
     */
    private static function allDomainEventClasses(): array
    {
        $files = glob(__DIR__.'/../../../src/Domain/EarningLine/Event/*.php');
        self::assertIsArray($files);

        $classes = [];

        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'Payroll\\Domain\\EarningLine\\Event\\'.basename($file, '.php');
            $reflection = new ReflectionClass($class);

            if ($reflection->isInterface() || ! $reflection->implementsInterface(DomainEvent::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
