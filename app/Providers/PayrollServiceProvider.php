<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Payroll\Application\Port\EarningLineRepository;
use Payroll\Application\Port\EventStore;
use Payroll\Infrastructure\EventStore\MySqlEventStore;
use Payroll\Infrastructure\Repository\EventSourcedEarningLineRepository;

/**
 * Where the framework is wired to the ports, and the only place that knows both.
 *
 * This is all the dependency injection this application needs: everything else is
 * constructor injection, and nothing in src/Domain or src/Application resolves
 * anything from the container.
 */
final class PayrollServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventStore::class, fn (): EventStore => new MySqlEventStore(
            $this->app->make(ConnectionInterface::class),
            $this->app->make(ConcurrencyErrorDetector::class),
        ));

        $this->app->bind(
            EarningLineRepository::class,
            fn (): EarningLineRepository => new EventSourcedEarningLineRepository(
                $this->app->make(EventStore::class),
            ),
        );
    }
}
