<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * Where a notification is delivered: endpoint + encryption keys + content encoding.
 */
final readonly class PushAddress
{
    public function __construct(
        private PushEndpoint $endpoint,
        private SubscriptionKeys $keys,
        private ContentEncoding $encoding = ContentEncoding::Aes128Gcm,
    ) {
    }

    public function fingerprint(): EndpointFingerprint
    {
        return $this->endpoint->fingerprint();
    }

    public function endpoint(): PushEndpoint
    {
        return $this->endpoint;
    }

    public function sameEndpointAs(self $other): bool
    {
        return $this->endpoint->equals($other->endpoint);
    }

    /** Proof of possession: whoever presents the same auth secret IS the device. */
    public function isProvenBy(self $presented): bool
    {
        return $this->keys->sameAuthAs($presented->keys);
    }

    public function authMatches(#[\SensitiveParameter] string $presentedAuth): bool
    {
        return $this->keys->authMatches($presentedAuth);
    }

    /**
     * @internal transport and persistence boundary only
     *
     * @return array{endpoint: string, p256dh: string, auth: string, encoding: string}
     */
    public function reveal(): array
    {
        return ['endpoint' => $this->endpoint->toString()] + $this->keys->revealForTransport() + ['encoding' => $this->encoding->value];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->endpoint->host(), 'fingerprint' => $this->fingerprint()->short(), 'encoding' => $this->encoding->value];
    }
}
