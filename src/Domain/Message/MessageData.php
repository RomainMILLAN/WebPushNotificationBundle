<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * Free application data carried to the service worker: flat, scalar, bounded.
 * No nested structures — the worker copies only allowlisted keys anyway.
 */
final readonly class MessageData
{
    private const MAX_ENTRIES = 16;
    private const MAX_STRING_LENGTH = 256;

    /**
     * @param array<string, string|int|float|bool> $entries
     */
    private function __construct(
        private array $entries,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<mixed> $entries
     */
    public static function fromEntries(array $entries): self
    {
        if (\count($entries) > self::MAX_ENTRIES) {
            throw InvalidValue::because(\sprintf('Cannot accept more than %d data entries.', self::MAX_ENTRIES));
        }

        $validated = [];
        foreach ($entries as $key => $value) {
            if (!\is_string($key) || 1 !== preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,31}$/D', $key)) {
                throw InvalidValue::because('Cannot accept a data key that is not 1 to 32 alphanumeric characters starting with a letter.');
            }

            if (!\is_scalar($value) || (\is_string($value) && u($value)->length() > self::MAX_STRING_LENGTH)) {
                throw InvalidValue::because(\sprintf('Cannot accept a data value that is not a scalar of at most %d characters.', self::MAX_STRING_LENGTH));
            }

            $validated[$key] = $value;
        }

        return new self($validated);
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function toPayload(): array
    {
        return $this->entries;
    }
}
