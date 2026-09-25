<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine;

use Doctrine\DBAL\Connection;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;

final readonly class DoctrineTransactionBoundary implements TransactionBoundary
{
    public function __construct(
        private Connection $connection,
        private AfterCommitCallbacks $afterCommitCallbacks,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $work());
    }

    public function afterCommit(callable $callback): void
    {
        if (!$this->connection->isTransactionActive()) {
            $callback();

            return;
        }

        $this->afterCommitCallbacks->defer($callback);
    }

    public function reset(): void
    {
        // DBAL writes do not close any EntityManager; after a failed statement,
        // transactional() already rolled back, so the connection is usable again.
    }
}
