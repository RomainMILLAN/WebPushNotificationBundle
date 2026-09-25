<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * SHA-256 of the canonical endpoint: carries the unique index, the lookups and the
 * logs. The endpoint itself is a capability URL and is never logged.
 *
 * Always derived from PushEndpoint::fingerprint(), never supplied by a caller;
 * reconstitute() only exists for persistence.
 */
final readonly class EndpointFingerprint
{
    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @internal derivation belongs to PushEndpoint
     */
    public static function fromCanonicalEndpoint(string $canonicalEndpoint): self
    {
        return new self(hash('sha256', $canonicalEndpoint));
    }

    /**
     * @internal persistence only
     */
    public static function reconstitute(string $value): self
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $value)) {
            throw InvalidValue::because('Cannot accept an endpoint fingerprint that is not 64 lowercase hexadecimal characters.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function lockKey(): LockKey
    {
        return LockKey::createForEndpoint($this);
    }

    /** The loggable form. */
    public function short(): string
    {
        return u($this->value)->slice(0, 12)->toString();
    }

    public function toString(): string
    {
        return $this->value;
    }
}
