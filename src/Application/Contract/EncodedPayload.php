<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Contract;

use RomainMillan\WebPushNotification\Application\Exception\InvalidPayload;

use function Symfony\Component\String\b;

/**
 * A payload in the published language (contract v1), ready to be encrypted.
 *
 * It is also the queue format: async messages carry this JSON string rather than a
 * serialized PHP object graph, so a deployment never breaks pending messages.
 */
final readonly class EncodedPayload
{
    private function __construct(
        private string $json,
        private string $messageId,
    ) {
    }

    /**
     * @internal produced by PayloadEncoder
     */
    public static function fromValidatedJson(string $json, string $messageId): self
    {
        return new self($json, $messageId);
    }

    /** Restores a payload that travelled through a queue. */
    public static function fromQueuedJson(string $json): self
    {
        if (b($json)->length() > PayloadEncoder::MAX_BYTES) {
            throw InvalidPayload::tooLarge(b($json)->length(), PayloadEncoder::MAX_BYTES);
        }

        try {
            $decoded = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidPayload::notContractV1();
        }

        if (!\is_array($decoded) || PayloadEncoder::VERSION !== ($decoded['v'] ?? null) || !\is_string($decoded['id'] ?? null)) {
            throw InvalidPayload::notContractV1();
        }

        return new self($json, $decoded['id']);
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function toString(): string
    {
        return $this->json;
    }
}
