<?php

declare(strict_types=1);

namespace Payroll\Infrastructure\EventStore\Exception;

use RuntimeException;

final class EventSerializationFailed extends RuntimeException
{
    public static function unknownType(string $type): self
    {
        return new self(sprintf(
            'No domain event is registered for stored type "%s".',
            $type,
        ));
    }

    public static function payloadIsNotAnObject(string $type): self
    {
        return new self(sprintf('Stored payload for "%s" is not a JSON object.', $type));
    }

    public static function missingField(string $type, string $field, string $expected): self
    {
        return new self(sprintf(
            'Stored payload for "%s" is missing field "%s" or it is not %s.',
            $type,
            $field,
            $expected,
        ));
    }
}
