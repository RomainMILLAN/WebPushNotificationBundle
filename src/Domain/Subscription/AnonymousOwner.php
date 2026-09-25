<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * Opt-in: a browser subscribed without an application identity (public site).
 *
 * Not a missing owner but a real case with its own behaviour: bounded by a global
 * cap instead of a per-owner quota, never targeted by a nominative send, and every
 * change of keys requires the auth secret.
 */
final readonly class AnonymousOwner implements Owner
{
    public function equals(Owner $other): bool
    {
        return $other->fold(static fn (): bool => false, static fn (): bool => true);
    }

    public function canBecome(Owner $next): bool
    {
        // Anonymous -> identified is a claim (with proof); anonymous -> anonymous is the same owner.
        return true;
    }

    public function renewalRequiresProof(): bool
    {
        return true;
    }

    public function lockKeys(): array
    {
        // No global anonymous lock: the anonymous cap is soft on purpose (see docs/consistency.md).
        return [];
    }

    public function subscriptionLimit(int $maxPerSubscriber, int $maxAnonymous): SubscriptionLimit
    {
        return new GlobalAnonymousCap($maxAnonymous);
    }

    public function fold(callable $identified, callable $anonymous): mixed
    {
        return $anonymous();
    }
}
