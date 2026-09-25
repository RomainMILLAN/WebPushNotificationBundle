<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Http;

use RomainMillan\WebPushNotification\Application\Exception\InvalidRequest;
use RomainMillan\WebPushNotification\Application\Exception\RequestTooLarge;
use RomainMillan\WebPushNotification\Application\Exception\UnsupportedMediaType;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * The body of a subscribe/unsubscribe request, read with the checks in order —
 * the order IS the check:
 * 1. media type (application/json only);
 * 2. size of what is ACTUALLY read (chunked requests have no Content-Length): the
 *    stream is read up to MAX_BYTES + 1 and refused beyond;
 * 3. json_decode with a bounded depth;
 * 4. shape — by SubscribeRequest / UnsubscribeRequest.
 *
 * Shared by both bridges: controllers stay ten-line customs officers.
 */
final readonly class JsonRequestBody
{
    public const MAX_BYTES = 4096;

    /**
     * @param array<mixed> $decoded
     */
    private function __construct(
        private array $decoded,
    ) {
    }

    /**
     * @param resource $stream
     */
    public static function fromStream(string $contentType, $stream): self
    {
        $mediaType = u($contentType)->before(';')->trim()->lower()->toString();

        if ('application/json' !== $mediaType) {
            throw UnsupportedMediaType::create();
        }

        $raw = stream_get_contents($stream, self::MAX_BYTES + 1);

        if (false === $raw) {
            throw InvalidRequest::because('Cannot read the request body.');
        }

        if (b($raw)->length() > self::MAX_BYTES) {
            throw RequestTooLarge::create(self::MAX_BYTES);
        }

        try {
            $decoded = json_decode($raw, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidRequest::because('Cannot decode the request body as a JSON object of depth 4 at most.');
        }

        if (!\is_array($decoded)) {
            throw InvalidRequest::because('Cannot accept a request body that is not a JSON object.');
        }

        return new self($decoded);
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return $this->decoded;
    }
}
