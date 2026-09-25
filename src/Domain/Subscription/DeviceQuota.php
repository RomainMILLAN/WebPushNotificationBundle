<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

/**
 * At most N active subscriptions per subscriber; making room evicts the least
 * recently registered ones.
 *
 * "Least recently registered", not "least recently used": the only signal the server
 * gets from a device is its (re-)registration, which the client loader repeats at
 * most daily.
 */
final readonly class DeviceQuota
{
    private function __construct(
        private int $max,
    ) {
    }

    public static function createAllowing(int $max): self
    {
        if ($max < 1) {
            throw InvalidValue::because('Cannot accept a subscription quota below 1.');
        }

        return new self($max);
    }

    /**
     * @return list<Subscription> the subscriptions to evict so that one more fits
     */
    public function evictionsToFitOneMore(OwnerSubscriptions $existing): array
    {
        $excess = $existing->count() + 1 - $this->max;

        return $excess > 0 ? $existing->leastRecentlyRegistered($excess) : [];
    }
}
