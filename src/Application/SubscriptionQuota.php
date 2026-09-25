<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionLimit;

/** Configured limits: per identified subscriber, and globally for anonymous ones. */
final readonly class SubscriptionQuota
{
    private function __construct(
        private int $maxPerSubscriber,
        private int $maxAnonymous,
    ) {
    }

    public static function fromLimits(int $maxPerSubscriber = 16, int $maxAnonymous = 10000): self
    {
        if ($maxPerSubscriber < 1 || $maxAnonymous < 0) {
            throw InvalidValue::because('Cannot accept a subscription quota below 1 per subscriber or a negative anonymous cap.');
        }

        return new self($maxPerSubscriber, $maxAnonymous);
    }

    public function limitFor(Owner $owner): SubscriptionLimit
    {
        return $owner->subscriptionLimit($this->maxPerSubscriber, $this->maxAnonymous);
    }
}
