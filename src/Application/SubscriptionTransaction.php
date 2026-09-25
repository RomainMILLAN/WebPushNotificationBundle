<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionLock;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionAlreadyExists;
use RomainMillan\WebPushNotification\Domain\Subscription\LockKey;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * The unit of work of every write use case: locks, one transaction, events after
 * commit, and a single replay when a concurrent registration won the unique index.
 */
final readonly class SubscriptionTransaction
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private TransactionBoundary $transactionBoundary,
        private SubscriptionLock $subscriptionLock,
        private EventPublisher $eventPublisher,
    ) {
    }

    /**
     * @template T
     *
     * @param list<LockKey> $keys
     * @param callable(): T $work
     *
     * @return T
     */
    public function locked(array $keys, callable $work): mixed
    {
        return [] === $keys ? $work() : $this->subscriptionLock->synchronized(LockKey::inAcquisitionOrder($keys), $work);
    }

    /**
     * @template T
     *
     * @param callable(SubscriptionSession): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        try {
            return $this->attempt($work);
        } catch (SubscriptionAlreadyExists) {
            // The endpoint lock makes this rare (non-shared lock store): the second
            // attempt finds the row and takes the upsert path of the matrix.
            $this->transactionBoundary->reset();

            return $this->attempt($work);
        }
    }

    /**
     * Read-only access, outside any lock: used to learn which keys to lock.
     *
     * @template T
     *
     * @param callable(SubscriptionRepository): T $read
     *
     * @return T
     */
    public function read(callable $read): mixed
    {
        return $read($this->subscriptionRepository);
    }

    /**
     * @template T
     *
     * @param callable(SubscriptionSession): T $work
     *
     * @return T
     */
    private function attempt(callable $work): mixed
    {
        $session = new SubscriptionSession($this->subscriptionRepository);

        $result = $this->transactionBoundary->run(static fn (): mixed => $work($session));
        $this->eventPublisher->publishAfterCommit($session->releaseEvents());

        return $result;
    }
}
