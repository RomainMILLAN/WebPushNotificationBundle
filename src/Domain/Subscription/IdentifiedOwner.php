<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

final readonly class IdentifiedOwner implements Owner
{
    public function __construct(
        private SubscriberId $subscriberId,
    ) {
    }

    public static function fromSubscriberId(string $subscriberId): self
    {
        return new self(SubscriberId::fromString($subscriberId));
    }

    public function equals(Owner $other): bool
    {
        return $other->fold(
            fn (SubscriberId $id): bool => $id->equals($this->subscriberId),
            static fn (): bool => false,
        );
    }

    public function canBecome(Owner $next): bool
    {
        return $next->fold(static fn (): bool => true, static fn (): bool => false);
    }

    public function renewalRequiresProof(): bool
    {
        // Authenticated by the application: being this owner IS the proof.
        return false;
    }

    public function lockKeys(): array
    {
        return [LockKey::createForSubscriber($this->subscriberId)];
    }

    public function subscriptionLimit(int $maxPerSubscriber, int $maxAnonymous): SubscriptionLimit
    {
        return new PerOwnerLimit($this, $maxPerSubscriber);
    }

    public function fold(callable $identified, callable $anonymous): mixed
    {
        return $identified($this->subscriberId);
    }
}
