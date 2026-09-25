<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

/**
 * The storage's transaction, abstracted.
 */
interface TransactionBoundary
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;

    /**
     * Runs $callback once the OUTERMOST transaction commits — immediately when none is
     * open — and never when it rolls back. This is what "events are published after
     * the real commit" rests on, including when the application wraps our use case in
     * its own transaction.
     *
     * @param callable(): void $callback
     */
    public function afterCommit(callable $callback): void;

    /**
     * Makes the storage usable again after a failed flush (Doctrine closes its
     * EntityManager on a constraint violation) before a replay.
     */
    public function reset(): void;
}
