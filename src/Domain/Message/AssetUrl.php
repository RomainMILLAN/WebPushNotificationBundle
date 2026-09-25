<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * An icon or badge image: an in-origin path, or an https URL.
 *
 * The service worker only displays in-origin images or hosts listed in its
 * configuration (asset_hosts): arbitrary image URLs in a payload would be a tracking
 * pixel fired on every display.
 */
final readonly class AssetUrl
{
    private function __construct(
        private string $url,
    ) {
    }

    public static function fromString(string $url): self
    {
        $candidate = u($url);
        $isPath = $candidate->startsWith('/') && !$candidate->startsWith('//');
        $isHttps = $candidate->startsWith('https://') && false !== filter_var($url, \FILTER_VALIDATE_URL);

        if ($candidate->length() > 2048 || !$isPath && !$isHttps || 1 === preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url)) {
            throw InvalidValue::because('Cannot accept an asset URL that is neither an in-origin path nor an https URL, or that carries whitespace, control characters or backslashes.');
        }

        return new self($url);
    }

    public function toString(): string
    {
        return $this->url;
    }
}
