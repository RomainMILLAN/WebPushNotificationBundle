<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * The application's own origin (scheme + host [+ port]), the reference for
 * "same-origin" rules on the PHP side — CLI and workers included, where there is no
 * request to read it from.
 */
final readonly class Origin
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $origin): self
    {
        $parts = parse_url($origin);
        $isHttps = \is_array($parts) && 'https' === ($parts['scheme'] ?? null);
        $isLocalHttp = \is_array($parts) && 'http' === ($parts['scheme'] ?? null) && \in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true);

        if (!\is_array($parts)
            || !$isHttps && !$isLocalHttp
            || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !\in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw InvalidValue::because('Cannot accept an origin other than https://host[:port] without path, query or credentials (http is only accepted for localhost).');
        }

        $value = ($isHttps ? 'https' : 'http').'://'.u($parts['host'])->lower()->toString().(isset($parts['port']) ? ':'.$parts['port'] : '');

        return new self($value);
    }

    public function isOriginOf(string $absoluteUrl): bool
    {
        return $absoluteUrl === $this->value || u($absoluteUrl)->startsWith($this->value.'/') || u($absoluteUrl)->startsWith($this->value.'?');
    }

    public function toString(): string
    {
        return $this->value;
    }
}
