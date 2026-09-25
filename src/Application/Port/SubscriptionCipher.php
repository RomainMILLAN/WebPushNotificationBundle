<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Port;

/**
 * Optional encryption at rest of the endpoint and keys, applied by the persistence
 * mappers. $context is bound as associated data (fingerprint + column): a ciphertext
 * moved to another row or column fails to decrypt.
 */
interface SubscriptionCipher
{
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string;

    public function decrypt(string $ciphertext, string $context): string;
}
