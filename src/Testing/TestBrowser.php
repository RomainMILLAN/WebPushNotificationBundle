<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Testing;

use RomainMillan\WebPushNotification\Domain\Subscription\ContentEncoding;
use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionKeys;

/**
 * A browser profile: one endpoint, and keys it can rotate. Real P-256 keys, so the
 * Minishlink encryption runs for real in transport tests.
 */
final class TestBrowser
{
    private string $p256dh;
    private string $auth;

    public function __construct(
        private readonly string $endpoint,
    ) {
        $this->rotateKeys();
    }

    public static function chrome(string $token = 'abc123'): self
    {
        return new self('https://fcm.googleapis.com/fcm/send/'.$token);
    }

    public static function safari(string $token = 'QAbc123'): self
    {
        return new self('https://web.push.apple.com/'.$token);
    }

    public static function firefox(string $token = 'gAAAAA'): self
    {
        return new self('https://updates.push.services.mozilla.com/wpush/v2/'.$token);
    }

    public function rotateKeys(): void
    {
        $this->p256dh = $this->generateP256dh();
        $this->auth = $this->base64Url(random_bytes(16));
    }

    public function address(): PushAddress
    {
        return new PushAddress(PushEndpoint::fromString($this->endpoint), SubscriptionKeys::fromStrings($this->p256dh, $this->auth), ContentEncoding::Aes128Gcm);
    }

    /** Same endpoint, forged keys: what an attacker who leaked the endpoint can present. */
    public function forgedAddress(): PushAddress
    {
        return new PushAddress(PushEndpoint::fromString($this->endpoint), SubscriptionKeys::fromStrings($this->generateP256dh(), $this->base64Url(random_bytes(16))), ContentEncoding::Aes128Gcm);
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function auth(): string
    {
        return $this->auth;
    }

    /**
     * @return array{endpoint: string, expirationTime: null, keys: array{p256dh: string, auth: string}}
     */
    public function toJson(): array
    {
        return ['endpoint' => $this->endpoint, 'expirationTime' => null, 'keys' => ['p256dh' => $this->p256dh, 'auth' => $this->auth]];
    }

    private function generateP256dh(): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        \assert(false !== $key);
        $details = openssl_pkey_get_details($key);
        \assert(\is_array($details) && \is_array($details['ec'] ?? null));

        /** @var array{x: string, y: string} $ec */
        $ec = $details['ec'];

        return $this->base64Url("\x04".str_pad($ec['x'], 32, "\x00", \STR_PAD_LEFT).str_pad($ec['y'], 32, "\x00", \STR_PAD_LEFT));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
