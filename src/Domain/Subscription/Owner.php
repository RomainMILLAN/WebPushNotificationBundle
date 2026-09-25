<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * Who a subscription belongs to: IdentifiedOwner or AnonymousOwner.
 *
 * A sum type instead of a nullable SubscriberId: every rule that differs between the
 * two cases is a method here, so no caller ever needs `instanceof` or `null ===`.
 */
interface Owner
{
    public function equals(self $other): bool;

    /**
     * An identified subscription must never be downgraded to anonymous: an anonymous
     * visitor would otherwise detach a user's device from their account.
     */
    public function canBecome(self $next): bool;

    /**
     * Whether a key rotation by this very owner still needs the auth secret.
     * "Same anonymous owner" proves nothing: any anonymous visitor is that owner.
     */
    public function renewalRequiresProof(): bool;

    /** @return list<LockKey> */
    public function lockKeys(): array;

    public function subscriptionLimit(int $maxPerSubscriber, int $maxAnonymous): SubscriptionLimit;

    /**
     * Case analysis for boundaries (persistence, logs, audiences).
     *
     * @template T
     *
     * @param callable(SubscriberId): T $identified
     * @param callable(): T             $anonymous
     *
     * @return T
     */
    public function fold(callable $identified, callable $anonymous): mixed;
}
