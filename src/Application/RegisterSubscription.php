<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use Psr\Clock\ClockInterface;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionIdGenerator;
use RomainMillan\WebPushNotification\Domain\Subscription\ClaimOutcome;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * Orchestrates a registration; the matrix itself lives in Subscription::claim().
 *
 * 1. allowlist first (neutral refusal);
 * 2. endpoint lock — from here the endpoint's owner cannot change under our feet;
 * 3. read the current owner, lock the owners involved (sorted);
 * 4. one transaction: claim or register, quota, save;
 * 5. events published after the real commit.
 */
final readonly class RegisterSubscription
{
    public function __construct(
        private SubscriptionTransaction $subscriptionTransaction,
        private RegistrationRules $registrationRules,
        private SubscriptionIdGenerator $subscriptionIdGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function register(Owner $claimant, PushAddress $presented): ClaimOutcome
    {
        if (!$this->registrationRules->allows($presented)) {
            return ClaimOutcome::RefusedHostNotAllowed;
        }

        $fingerprint = $presented->fingerprint();

        return $this->subscriptionTransaction->locked([$fingerprint->lockKey()], function () use ($claimant, $presented, $fingerprint): ClaimOutcome {
            $previousOwnerKeys = $this->subscriptionTransaction->read(
                static fn (SubscriptionRepository $subscriptions): array => $subscriptions->hasFingerprint($fingerprint)
                    ? $subscriptions->getByFingerprint($fingerprint)->ownerLockKeys()
                    : [],
            );

            return $this->subscriptionTransaction->locked(
                [...$previousOwnerKeys, ...$claimant->lockKeys()],
                fn (): ClaimOutcome => $this->subscriptionTransaction->transactional(
                    fn (SubscriptionSession $session): ClaimOutcome => $this->claimWithin($session, $claimant, $presented),
                ),
            );
        });
    }

    private function claimWithin(SubscriptionSession $session, Owner $claimant, PushAddress $presented): ClaimOutcome
    {
        $now = $this->clock->now();

        if ($session->hasFingerprint($presented->fingerprint())) {
            $subscription = $session->getByFingerprint($presented->fingerprint());
            $outcome = $subscription->claim($claimant, $presented, $now);
        } else {
            $subscription = Subscription::register($this->subscriptionIdGenerator->generate(), $claimant, $presented, $now);
            $outcome = ClaimOutcome::Registered;
        }

        if ($outcome->isRefused()) {
            return $outcome;
        }

        if ($outcome->requiresQuota() && !$this->registrationRules->makeRoomFor($subscription, $claimant, $session, $now)) {
            // The aggregate may hold unsaved changes: they are simply never saved.
            return ClaimOutcome::RefusedAnonymousCap;
        }

        $session->save($subscription);

        return $outcome;
    }
}
