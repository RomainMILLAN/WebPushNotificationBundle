<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Crypto;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * A 32-byte key and its identifier, configured as "keyId:base64key".
 *
 * Dedicated: never derived from kernel.secret / APP_KEY, never reused as the HMAC
 * secret of the client state marker.
 */
final readonly class EncryptionKey
{
    private function __construct(
        private string $id,
        #[\SensitiveParameter]
        private string $bytes,
    ) {
    }

    public static function fromConfiguration(#[\SensitiveParameter] string $configured): self
    {
        $parts = u($configured)->split(':', 2);

        if (2 !== \count($parts) || 1 !== preg_match('/^[a-zA-Z0-9_-]{1,16}$/D', $parts[0]->toString())) {
            throw InvalidValue::because('Cannot accept an encryption key that is not configured as "keyId:base64key" (keyId: 1 to 16 characters).');
        }

        $bytes = base64_decode($parts[1]->toString(), true);

        if (false === $bytes || \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== b($bytes)->length()) {
            throw InvalidValue::because('Cannot accept an encryption key that does not decode to exactly 32 bytes.');
        }

        return new self($parts[0]->toString(), $bytes);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function hasId(string $id): bool
    {
        return hash_equals($this->id, $id);
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $associatedData): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $this->bytes);
    }

    public function decrypt(string $nonceAndCiphertext, string $associatedData): string|false
    {
        $nonce = b($nonceAndCiphertext)->slice(0, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES)->toString();
        $ciphertext = b($nonceAndCiphertext)->slice(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES)->toString();

        return sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $associatedData, $nonce, $this->bytes);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'bytes' => '[redacted]'];
    }
}
