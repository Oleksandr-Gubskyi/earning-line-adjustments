<?php

declare(strict_types=1);

namespace Payroll\Domain\EarningLine\Event;

/**
 * A business fact recorded by the EarningLine aggregate.
 *
 * Deliberately empty. Events describe what happened and know nothing about how
 * the store writes them down: the mapping between an event and its stored
 * payload lives in the infrastructure serializer.
 *
 * Events also do not carry the earning line id. The stream identifies the
 * aggregate, so repeating the id in every payload would duplicate what the
 * stream_id column already holds.
 */
interface DomainEvent {}
