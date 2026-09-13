<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Payroll\Application\Port\EventStore;
use Payroll\Infrastructure\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;
use Tests\Contract\EventStoreContract;

final class InMemoryEventStoreTest extends TestCase
{
    use EventStoreContract;

    protected function createStore(): EventStore
    {
        return new InMemoryEventStore;
    }
}
