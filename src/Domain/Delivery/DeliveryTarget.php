<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

/**
 * Everything — and only what — the transport needs to reach one device.
 *
 * @internal carries secrets: never log, serialize or dump it
 */
final readonly class DeliveryTarget
{
    public function __construct(
        private SubscriptionId $subscriptionId,
        private PushAddress $address,
    ) {
    }

    public function subscriptionId(): SubscriptionId
    {
        return $this->subscriptionId;
    }

    /** DNS pinning and logs work on the host, never on the full URL. */
    public function host(): string
    {
        return $this->address->endpoint()->host();
    }

    public function shortFingerprint(): string
    {
        return $this->address->fingerprint()->short();
    }

    /**
     * @return array{endpoint: string, p256dh: string, auth: string, encoding: string}
     */
    public function reveal(): array
    {
        return $this->address->reveal();
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['subscriptionId' => $this->subscriptionId->toString(), 'host' => $this->host(), 'fingerprint' => $this->shortFingerprint()];
    }
}
