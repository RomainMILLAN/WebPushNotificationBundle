<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Contract;

use RomainMillan\WebPushNotification\Application\Exception\InvalidPayload;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

use function Symfony\Component\String\b;

/**
 * Projects a WebPushMessage into the published language shared with the service
 * worker: contract v1, described in schema/v1.json and docs/payload-contract.md.
 *
 * The size limit is the padding target of minishlink/web-push (2820 bytes, minus the
 * aes128gcm delimiter byte): every payload that fits is padded to the SAME encrypted
 * size, so the length of a notification leaks nothing about its content, and it fits
 * every push service.
 */
final readonly class PayloadEncoder
{
    public const VERSION = 1;
    public const MAX_BYTES = 2819;

    public function encode(WebPushMessage $message): EncodedPayload
    {
        $payload = ['v' => self::VERSION] + $message->toPayload();
        // An empty PHP array would encode as [] — the contract says object.
        $payload['data'] = (object) ($payload['data'] ?? []);

        $json = json_encode(
            $payload,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );

        if (b($json)->length() > self::MAX_BYTES) {
            throw InvalidPayload::tooLarge(b($json)->length(), self::MAX_BYTES);
        }

        return EncodedPayload::fromValidatedJson($json, $message->id());
    }
}
