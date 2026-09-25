<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Proof;

use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\UnsubscribeCause;

/** The authenticated owner removes their own device. */
final readonly class OwnerProof implements UnsubscribeProof
{
    public function __construct(
        private Owner $owner,
    ) {
    }

    public function entitles(Subscription $subscription): bool
    {
        // An anonymous "owner" proves nothing: anonymous removals need PossessionProof.
        return $this->owner->fold(
            fn (): bool => $subscription->isOwnedBy($this->owner),
            static fn (): bool => false,
        );
    }

    public function cause(): UnsubscribeCause
    {
        return UnsubscribeCause::Owner;
    }
}
