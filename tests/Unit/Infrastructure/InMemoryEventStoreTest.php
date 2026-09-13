<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Payroll\Application\Port\EventStore;
use Payroll\Infrastructure\EventStore\InMemoryEventStore;
use Tests\Contract\EventStoreContract;

final class InMemoryEventStoreTest extends EventStoreContract
{
    protected function createStore(): EventStore
    {
        return new InMemoryEventStore;
    }
}
