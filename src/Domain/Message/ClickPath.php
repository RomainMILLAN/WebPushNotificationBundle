<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * Where a click on the notification leads: an in-origin path, never a URL.
 *
 * A ClickPath CANNOT carry a host — its constructor refuses it. The service worker's
 * safeClickPath() is its distrustful twin on the client side.
 */
final readonly class ClickPath
{
    private const MAX_LENGTH = 2048;

    private function __construct(
        private string $path,
    ) {
    }

    public static function fromString(string $path): self
    {
        $candidate = u($path);

        if ($candidate->isEmpty() || $candidate->length() > self::MAX_LENGTH) {
            throw InvalidValue::because(\sprintf('Cannot accept a click path that is empty or longer than %d characters.', self::MAX_LENGTH));
        }

        // Scheme-relative (//evil.example) resolves off-origin in browsers.
        if (!$candidate->startsWith('/') || $candidate->startsWith('//')) {
            throw InvalidValue::because('Cannot accept a click path that is not an in-origin absolute path starting with a single "/".');
        }

        if ($candidate->containsAny(['://', '\\']) || 1 === preg_match('/[\s\x00-\x1F\x7F]/', $path)) {
            throw InvalidValue::because('Cannot accept a click path carrying a host, backslashes, whitespace or control characters.');
        }

        return new self($path);
    }

    public function toString(): string
    {
        return $this->path;
    }
}
