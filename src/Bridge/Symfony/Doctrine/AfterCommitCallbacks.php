<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine;

/**
 * Callbacks waiting for the real commit of the connection.
 *
 * Fed by DoctrineTransactionBoundary, flushed by the DBAL driver middleware — which
 * only sees the OUTERMOST commit (nested transactions are savepoints and never reach
 * the driver). So events are published after the real commit even when the
 * application wraps our use case in its own transaction.
 */
final class AfterCommitCallbacks
{
    /** @var list<callable(): void> */
    private array $callbacks = [];

    /**
     * @param callable(): void $callback
     */
    public function defer(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function runAfterCommit(): void
    {
        while ([] !== $this->callbacks) {
            $callback = array_shift($this->callbacks);
            $callback();
        }
    }

    public function discardAfterRollback(): void
    {
        $this->callbacks = [];
    }
}
