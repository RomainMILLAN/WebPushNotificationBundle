<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Proof;

use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\UnsubscribeCause;

/**
 * The device itself: whoever holds the auth secret IS the browser. Accepted for any
 * owner, without a session (logout flow, anonymous visitors) — the only thing it can
 * ever do is remove its own subscription.
 */
final readonly class PossessionProof implements UnsubscribeProof
{
    public function __construct(
        #[\SensitiveParameter]
        private string $auth,
    ) {
    }

    public function entitles(Subscription $subscription): bool
    {
        return $subscription->isProvenBy($this->auth);
    }

    public function cause(): UnsubscribeCause
    {
        return UnsubscribeCause::Possession;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['auth' => '[redacted]'];
    }
}
