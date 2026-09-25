<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * A same-origin URL the service worker POSTs to when an action button is pressed
 * (e.g. "acknowledge"), from the lock screen, with the user's cookies.
 *
 * It MUST carry its own authorization — a short-lived signed URL (UriSigner /
 * URL::temporarySignedRoute), idempotent — never rely on the session alone: no CSRF
 * token travels with that request.
 */
final readonly class ActionUrl
{
    private const MAX_LENGTH = 2048;

    private function __construct(
        private string $url,
    ) {
    }

    public static function fromString(string $url, Origin $origin): self
    {
        $candidate = u($url);

        if ($candidate->isEmpty() || $candidate->length() > self::MAX_LENGTH || 1 === preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url)) {
            throw InvalidValue::because('Cannot accept an action URL that is empty, longer than 2048 characters, or carries whitespace, control characters or backslashes.');
        }

        if ($candidate->startsWith('/') && !$candidate->startsWith('//')) {
            $url = $origin->toString().$url;
        }

        if (!$origin->isOriginOf($url)) {
            throw InvalidValue::because('Cannot accept an action URL outside the application origin.');
        }

        return new self($url);
    }

    public function toString(): string
    {
        return $this->url;
    }
}
