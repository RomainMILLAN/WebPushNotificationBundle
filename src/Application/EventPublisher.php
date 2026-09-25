<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use Psr\EventDispatcher\EventDispatcherInterface;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;

/**
 * Publishes domain events AFTER the real commit: a synchronous listener must never
 * receive a fact that a rollback then undid.
 */
final readonly class EventPublisher
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private TransactionBoundary $transactionBoundary,
    ) {
    }

    /**
     * @param list<object> $events
     */
    public function publishAfterCommit(array $events): void
    {
        if ([] === $events) {
            return;
        }

        $this->transactionBoundary->afterCommit(function () use ($events): void {
            foreach ($events as $event) {
                $this->eventDispatcher->dispatch($event);
            }
        });
    }
}
