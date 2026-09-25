<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\AfterCommitCallbacks;

/**
 * The driver connection only receives the outermost commit/rollBack: nested
 * transactions are emulated with savepoints by the wrapper connection. Inheritance
 * imposed by DBAL's middleware extension point.
 */
final class AfterCommitConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $wrappedConnection,
        private readonly AfterCommitCallbacks $afterCommitCallbacks,
    ) {
        parent::__construct($wrappedConnection);
    }

    public function commit(): void
    {
        parent::commit();

        $this->afterCommitCallbacks->runAfterCommit();
    }

    public function rollBack(): void
    {
        $this->afterCommitCallbacks->discardAfterRollback();

        parent::rollBack();
    }
}
