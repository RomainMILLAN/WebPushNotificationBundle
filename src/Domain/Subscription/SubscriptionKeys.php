<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * The browser's ECDH public key (p256dh) and auth secret.
 *
 * No getters: the auth secret is compared here (authMatches) and revealed to the
 * transport only, under an explicit name. Anything else asking for the keys is a leak
 * in the making.
 */
final readonly class SubscriptionKeys
{
    private const P256DH_LENGTH = 87;
    private const AUTH_LENGTH = 22;

    private function __construct(
        #[\SensitiveParameter]
        private string $p256dh,
        #[\SensitiveParameter]
        private string $auth,
    ) {
    }

    public static function fromStrings(#[\SensitiveParameter] string $p256dh, #[\SensitiveParameter] string $auth): self
    {
        self::assertBase64UrlOfLength($p256dh, self::P256DH_LENGTH, 'p256dh');
        self::assertBase64UrlOfLength($auth, self::AUTH_LENGTH, 'auth');

        return new self($p256dh, $auth);
    }

    /** Constant-time: the auth secret is the proof of possession of a subscription. */
    public function authMatches(#[\SensitiveParameter] string $presentedAuth): bool
    {
        return hash_equals($this->auth, $presentedAuth);
    }

    public function sameAuthAs(self $other): bool
    {
        return hash_equals($this->auth, $other->auth);
    }

    /**
     * @internal transport and persistence boundary only
     *
     * @return array{p256dh: string, auth: string}
     */
    public function revealForTransport(): array
    {
        return ['p256dh' => $this->p256dh, 'auth' => $this->auth];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['p256dh' => '[redacted]', 'auth' => '[redacted]'];
    }

    private static function assertBase64UrlOfLength(string $value, int $expectedLength, string $name): void
    {
        if (u($value)->length() !== $expectedLength || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw InvalidValue::because(\sprintf('Cannot accept a push subscription %s that is not exactly %d base64url characters.', $name, $expectedLength));
        }
    }
}
