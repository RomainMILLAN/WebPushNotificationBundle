<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryTarget;
use RomainMillan\WebPushNotification\Domain\Exception\InvariantViolated;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionEvent;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionEvicted;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionExpired;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionReactivated;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionReassigned;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionRegistered;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionRenewed;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionUnsubscribed;

/**
 * Aggregate root: one browser's push subscription.
 *
 * claim() is the ONLY entry point of the registration matrix. The transitions it
 * delegates to are private and guard their own invariants anyway, so the most
 * sensitive rule — a device only changes hands on proof of possession of its auth
 * secret — cannot be bypassed by an application loading the aggregate directly.
 *
 * No getters: persistence goes through snapshot() (a Memento), delivery through
 * deliveryTarget().
 */
final class Subscription
{
    /** @var list<SubscriptionEvent> */
    private array $events = [];

    private function __construct(
        private readonly SubscriptionId $id,
        private Owner $owner,
        private PushAddress $address,
        private Lifecycle $lifecycle,
    ) {
    }

    public static function register(SubscriptionId $id, Owner $owner, PushAddress $address, \DateTimeImmutable $at): self
    {
        $subscription = new self($id, $owner, $address, Lifecycle::createRegisteredAt($at));
        $subscription->events[] = new SubscriptionRegistered($id, $address->fingerprint(), $owner, $at);

        return $subscription;
    }

    /**
     * @internal persistence only — re-validates the endpoint shape, never the allowlist
     */
    public static function reconstitute(SubscriptionId $id, Owner $owner, PushAddress $address, Lifecycle $lifecycle): self
    {
        return new self($id, $owner, $address, $lifecycle);
    }

    /**
     * The registration matrix for a browser presenting an endpoint this aggregate
     * already holds. Refusals leave the aggregate untouched.
     */
    public function claim(Owner $claimant, PushAddress $presented, \DateTimeImmutable $at): ClaimOutcome
    {
        if (!$this->address->sameEndpointAs($presented)) {
            throw InvariantViolated::differentEndpoint();
        }

        if ($this->owner->equals($claimant)) {
            if ($this->owner->renewalRequiresProof() && !$this->address->isProvenBy($presented)) {
                return ClaimOutcome::RefusedAuthMismatch;
            }

            if (!$this->lifecycle->isActive()) {
                $this->reactivate($claimant, $presented, $at);

                return ClaimOutcome::Reactivated;
            }

            $this->renew($presented, $at);

            return ClaimOutcome::Renewed;
        }

        if (!$this->owner->canBecome($claimant)) {
            return ClaimOutcome::RefusedDowngrade;
        }

        if (!$this->address->isProvenBy($presented)) {
            return ClaimOutcome::RefusedAuthMismatch;
        }

        if (!$this->lifecycle->isActive()) {
            $this->reactivate($claimant, $presented, $at);

            return ClaimOutcome::Reactivated;
        }

        $this->reassignTo($claimant, $presented, $at);

        return ClaimOutcome::Reassigned;
    }

    public function expire(\DateTimeImmutable $at): void
    {
        $this->lifecycle = $this->lifecycle->retiredAt(RetirementReason::Expired, $at);
        $this->events[] = new SubscriptionExpired($this->id, $this->address->fingerprint(), $this->owner, $at);
    }

    public function evict(\DateTimeImmutable $at): void
    {
        $this->lifecycle = $this->lifecycle->retiredAt(RetirementReason::Evicted, $at);
        $this->events[] = new SubscriptionEvicted($this->id, $this->address->fingerprint(), $this->owner, $at);
    }

    /** Records the fact; the caller then removes the aggregate from the repository. */
    public function unsubscribe(UnsubscribeCause $cause, \DateTimeImmutable $at): void
    {
        $this->events[] = new SubscriptionUnsubscribed($this->id, $this->address->fingerprint(), $this->owner, $cause, $at);
    }

    public function id(): SubscriptionId
    {
        return $this->id;
    }

    public function fingerprint(): EndpointFingerprint
    {
        return $this->address->fingerprint();
    }

    /**
     * The keys to hold before touching this subscription: its endpoint, then its
     * current owner — the global acquisition order.
     *
     * @return list<LockKey>
     */
    public function lockKeys(): array
    {
        return [$this->fingerprint()->lockKey(), ...$this->ownerLockKeys()];
    }

    /**
     * For a caller already holding the endpoint key: locks are not reentrant.
     *
     * @return list<LockKey>
     */
    public function ownerLockKeys(): array
    {
        return $this->owner->lockKeys();
    }

    /** Re-checked at delivery: a host removed from the configuration stops deliveries. */
    public function isServedBy(AllowedPushServices $services): bool
    {
        return $services->permits($this->address->endpoint());
    }

    public function isActive(): bool
    {
        return $this->lifecycle->isActive();
    }

    public function isOwnedBy(Owner $owner): bool
    {
        return $this->owner->equals($owner);
    }

    public function isRegisteredBefore(self $other): bool
    {
        return $this->lifecycle->isRegisteredBefore($other->lifecycle);
    }

    public function isRetiredSince(\DateTimeImmutable $cutoff): bool
    {
        return $this->lifecycle->isRetiredSince($cutoff);
    }

    /** Proof of possession, compared in constant time. */
    public function isProvenBy(#[\SensitiveParameter] string $presentedAuth): bool
    {
        return $this->address->authMatches($presentedAuth);
    }

    /**
     * @internal the only way out to the transport
     */
    public function deliveryTarget(): DeliveryTarget
    {
        return new DeliveryTarget($this->id, $this->address);
    }

    /**
     * @internal persistence Memento
     */
    public function snapshot(): SubscriptionSnapshot
    {
        $revealed = $this->address->reveal();

        return new SubscriptionSnapshot(
            id: $this->id->toString(),
            subscriberId: $this->owner->fold(static fn (SubscriberId $id): string => $id->toString(), static fn (): ?string => null),
            address: [
                'endpoint' => $revealed['endpoint'],
                'endpoint_hash' => $this->address->fingerprint()->toString(),
                'host' => $this->address->endpoint()->host(),
                'p256dh' => $revealed['p256dh'],
                'auth' => $revealed['auth'],
                'content_encoding' => $revealed['encoding'],
            ],
            lifecycle: $this->lifecycle->snapshot(),
        );
    }

    /**
     * @return list<SubscriptionEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id->toString(),
            'fingerprint' => $this->address->fingerprint()->short(),
            'active' => $this->lifecycle->isActive(),
        ];
    }

    private function renew(PushAddress $presented, \DateTimeImmutable $at): void
    {
        $this->address = $presented;
        $this->lifecycle = $this->lifecycle->renewedAt($at);
        $this->events[] = new SubscriptionRenewed($this->id, $this->address->fingerprint(), $this->owner, $at);
    }

    private function reassignTo(Owner $next, PushAddress $presented, \DateTimeImmutable $at): void
    {
        $this->guardHandOver($next, $presented);

        $previous = $this->owner;
        $this->owner = $next;
        $this->address = $presented;
        $this->lifecycle = $this->lifecycle->renewedAt($at);
        $this->events[] = new SubscriptionReassigned($this->id, $this->address->fingerprint(), $previous, $next, $at);
    }

    private function reactivate(Owner $claimant, PushAddress $presented, \DateTimeImmutable $at): void
    {
        $previous = $this->owner;

        if (!$previous->equals($claimant)) {
            $this->guardHandOver($claimant, $presented);
        }

        $this->owner = $claimant;
        $this->address = $presented;
        $this->lifecycle = $this->lifecycle->reactivatedAt($at);
        $this->events[] = new SubscriptionReactivated($this->id, $this->address->fingerprint(), $claimant, $at);

        if (!$previous->equals($claimant)) {
            $this->events[] = new SubscriptionReassigned($this->id, $this->address->fingerprint(), $previous, $claimant, $at);
        }
    }

    private function guardHandOver(Owner $next, PushAddress $presented): void
    {
        if (!$this->owner->canBecome($next)) {
            throw InvariantViolated::ownerDowngrade();
        }

        if (!$this->address->isProvenBy($presented)) {
            throw InvariantViolated::possessionNotProven();
        }
    }
}
