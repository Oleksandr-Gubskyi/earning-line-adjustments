<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use Payroll\Application\Exception\ConcurrencyConflict;
use Payroll\Domain\EarningLine\EarningLineId;
use Payroll\Domain\EarningLine\Event\EarningLineCalculated;
use Payroll\Domain\Money;
use Payroll\Infrastructure\EventStore\MySqlEventStore;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Which database failures become a ConcurrencyConflict, and which must not.
 *
 * The happy path and the duplicate key are proven against a real database in the
 * integration suite. Deadlocks and lock-wait timeouts cannot be reproduced
 * reliably there, so the translation itself is pinned here with a crafted
 * exception -- using the framework's own detector, not a copy of its rules.
 */
final class MySqlEventStoreTranslationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_a_duplicate_key_becomes_a_concurrency_conflict(): void
    {
        $store = $this->storeThatFailsWith(self::queryException(
            UniqueConstraintViolationException::class,
            "Integrity constraint violation: 1062 Duplicate entry '...' for key 'uniq_stream_version'",
            '23000',
        ));

        $this->expectException(ConcurrencyConflict::class);

        $store->append(EarningLineId::generate(), 0, [self::anEvent()]);
    }

    public function test_a_deadlock_becomes_a_concurrency_conflict(): void
    {
        // A deadlock does not arrive as DeadlockException: the framework only raises
        // that from a nested transaction, so at this level it is a QueryException.
        $store = $this->storeThatFailsWith(self::queryException(
            QueryException::class,
            'Deadlock found when trying to get lock; try restarting transaction',
            '40001',
        ));

        $this->expectException(ConcurrencyConflict::class);

        $store->append(EarningLineId::generate(), 0, [self::anEvent()]);
    }

    public function test_a_lock_wait_timeout_becomes_a_concurrency_conflict(): void
    {
        $store = $this->storeThatFailsWith(self::queryException(
            QueryException::class,
            'Lock wait timeout exceeded; try restarting transaction',
            'HY000',
        ));

        $this->expectException(ConcurrencyConflict::class);

        $store->append(EarningLineId::generate(), 0, [self::anEvent()]);
    }

    public function test_any_other_database_failure_is_left_alone(): void
    {
        // Turning every database failure into a conflict would send the caller into
        // a reload-and-retry loop over a problem that retrying cannot fix.
        $store = $this->storeThatFailsWith(self::queryException(
            QueryException::class,
            "Base table or view not found: 1146 Table 'payroll.domain_events' doesn't exist",
            '42S02',
        ));

        $this->expectException(QueryException::class);

        $store->append(EarningLineId::generate(), 0, [self::anEvent()]);
    }

    public function test_nothing_is_attempted_when_there_are_no_events(): void
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldNotReceive('transaction');

        $store = new MySqlEventStore($connection, new ConcurrencyErrorDetector);

        $store->append(EarningLineId::generate(), 0, []);

        // The assertion is the mock expectation above, verified by Mockery::close().
        $this->addToAssertionCount(1);
    }

    private function storeThatFailsWith(Throwable $failure): MySqlEventStore
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('transaction')->once()->andThrow($failure);

        return new MySqlEventStore($connection, new ConcurrencyErrorDetector);
    }

    /**
     * @param  class-string<QueryException>  $class
     */
    private static function queryException(string $class, string $driverMessage, string $sqlState): QueryException
    {
        $pdo = new PDOException('SQLSTATE['.$sqlState.']: '.$driverMessage);
        $pdo->errorInfo = [$sqlState, 1062, $driverMessage];

        return new $class('mysql', 'insert into domain_events ...', [], $pdo);
    }

    private static function anEvent(): EarningLineCalculated
    {
        return new EarningLineCalculated(Money::fromDecimalString('1000.00'));
    }
}
