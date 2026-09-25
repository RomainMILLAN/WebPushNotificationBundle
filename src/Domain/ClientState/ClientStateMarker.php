<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\ClientState;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

/**
 * Which account the browser-side state (service worker navigation intent) belongs to.
 *
 * A pseudonymous tag, not an authorization: it only lets the page and the worker
 * detect that the account changed and wipe what belonged to the previous one.
 */
final readonly class ClientStateMarker
{
    private function __construct(
        private string $digest,
    ) {
    }

    public static function fromDigest(string $digest): self
    {
        if (1 !== preg_match('/^[0-9a-f]{16}$/D', $digest)) {
            throw InvalidValue::because('Cannot accept a client state marker that is not 16 lowercase hexadecimal characters.');
        }

        return new self($digest);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->digest, $other->digest);
    }

    public function toString(): string
    {
        return $this->digest;
    }
}
