<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionLock;
use RomainMillan\WebPushNotification\Domain\Subscription\LockKey;

/**
 * Behaves like a real lock store in one respect that matters: locks are NOT
 * reentrant. Acquiring a key already held fails loudly instead of deadlocking.
 */
final class StrictSubscriptionLock implements SubscriptionLock
{
    /** @var array<string, true> */
    private array $held = [];

    /** @var list<list<string>> */
    public array $acquisitions = [];

    public function synchronized(array $keys, callable $work): mixed
    {
        $ordered = array_map(static fn (LockKey $key): string => $key->toString(), LockKey::inAcquisitionOrder($keys));

        foreach ($ordered as $key) {
            if (isset($this->held[$key])) {
                throw new \LogicException(\sprintf('Lock "%s" acquired twice: locks are not reentrant.', $key));
            }
        }

        $this->acquisitions[] = $ordered;
        foreach ($ordered as $key) {
            $this->held[$key] = true;
        }

        try {
            return $work();
        } finally {
            foreach ($ordered as $key) {
                unset($this->held[$key]);
            }
        }
    }
}
