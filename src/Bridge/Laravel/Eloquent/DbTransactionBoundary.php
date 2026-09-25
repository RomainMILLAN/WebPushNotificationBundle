<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent;

use Illuminate\Database\Connection;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;

/**
 * DB::transaction() of the subscriptions' connection. Nested in an application
 * transaction, it becomes a savepoint, and afterCommit() waits for the OUTERMOST
 * commit — which is what "events after the real commit" requires.
 */
final readonly class DbTransactionBoundary implements TransactionBoundary
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->connection->transaction(static fn (): mixed => $work());
    }

    public function afterCommit(callable $callback): void
    {
        // Runs the callback at once when no transaction is open, never on rollback.
        $this->connection->afterCommit($callback);
    }

    public function reset(): void
    {
        // Nothing to do: Eloquent keeps no unit of work that a failed statement would
        // close (unlike Doctrine's EntityManager), and DB::transaction() already
        // rolled back.
    }
}
