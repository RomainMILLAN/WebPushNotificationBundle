<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Crypto;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionCipher;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * XChaCha20-Poly1305 (libsodium), stored as "v1:<keyId>:<base64(nonce . ciphertext)>".
 *
 * - AEAD with associated data = fingerprint + column: a ciphertext copied onto another
 *   row or column no longer authenticates;
 * - a random 24-byte nonce per value;
 * - versioned format + keyring (current, previous...): keys rotate without a mass
 *   rewrite — values are re-encrypted with the current key when next saved.
 */
final readonly class AeadSubscriptionCipher implements SubscriptionCipher
{
    private const FORMAT = 'v1';

    /**
     * @param list<EncryptionKey> $previousKeys
     */
    public function __construct(
        private EncryptionKey $currentKey,
        private array $previousKeys = [],
    ) {
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string
    {
        return self::FORMAT.':'.$this->currentKey->id().':'.base64_encode($this->currentKey->encrypt($plaintext, $context));
    }

    public function decrypt(string $ciphertext, string $context): string
    {
        $parts = u($ciphertext)->split(':', 3);

        if (3 !== \count($parts) || self::FORMAT !== $parts[0]->toString()) {
            throw InvalidValue::because('Cannot decrypt a value that is not in the v1 encrypted format.');
        }

        $sealed = base64_decode($parts[2]->toString(), true);

        foreach ([$this->currentKey, ...$this->previousKeys] as $key) {
            if (false === $sealed || !$key->hasId($parts[1]->toString())) {
                continue;
            }

            $plaintext = $key->decrypt($sealed, $context);

            if (false !== $plaintext) {
                return $plaintext;
            }
        }

        throw InvalidValue::because('Cannot decrypt a value with the configured keys (unknown key id, or value moved to another row).');
    }
}
