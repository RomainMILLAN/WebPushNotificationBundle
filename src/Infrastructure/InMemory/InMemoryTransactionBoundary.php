<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\InMemory;

use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;

/**
 * Reference adapter for tests: tracks nesting and after-commit callbacks (dropped on
 * rollback). Stored rows themselves are not rolled back.
 */
final class InMemoryTransactionBoundary implements TransactionBoundary
{
    private int $depth = 0;

    /** @var list<callable(): void> */
    private array $afterCommit = [];

    public function run(callable $work): mixed
    {
        ++$this->depth;

        try {
            $result = $work();
        } catch (\Throwable $failure) {
            --$this->depth;

            if (0 === $this->depth) {
                $this->afterCommit = [];
            }

            throw $failure;
        }

        --$this->depth;

        if (0 === $this->depth) {
            $this->flushAfterCommit();
        }

        return $result;
    }

    public function afterCommit(callable $callback): void
    {
        $this->afterCommit[] = $callback;

        if (0 === $this->depth) {
            $this->flushAfterCommit();
        }
    }

    public function reset(): void
    {
    }

    private function flushAfterCommit(): void
    {
        $callbacks = $this->afterCommit;
        $this->afterCommit = [];

        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
