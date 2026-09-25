<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Crypto;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionCipher;

/**
 * Default: no encryption at rest. Endpoint and auth secret are then secrets that end
 * up in database backups — see docs/security.md before choosing this in production.
 */
final readonly class NullSubscriptionCipher implements SubscriptionCipher
{
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string
    {
        return $plaintext;
    }

    public function decrypt(string $ciphertext, string $context): string
    {
        return $ciphertext;
    }
}
