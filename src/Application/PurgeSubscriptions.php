<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionsPurged;
use RomainMillan\WebPushNotification\Domain\Subscription\PurgeableSubscriptions;

/**
 * Storage limitation (GDPR): retired subscriptions kept for display are deleted after
 * a while, and so are anonymous subscriptions whose browser stopped re-registering.
 */
final readonly class PurgeSubscriptions
{
    public function __construct(
        private PurgeableSubscriptions $purgeableSubscriptions,
        private PurgeWindows $purgeWindows,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    public function purge(): SubscriptionsPurged
    {
        $now = $this->clock->now();

        $purged = new SubscriptionsPurged(
            $this->purgeableSubscriptions->deleteRetiredBefore($this->purgeWindows->retiredCutoff($now)),
            $this->purgeableSubscriptions->deleteStaleAnonymousBefore($this->purgeWindows->anonymousCutoff($now)),
            $now,
        );

        $this->eventDispatcher->dispatch($purged);

        return $purged;
    }
}
