<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

/**
 * Notifications sharing a tag replace each other instead of stacking. Defaults to the
 * message id: a duplicate caused by a retry then replaces the first display.
 */
final readonly class Tag
{
    private function __construct(
        private string $value,
        private bool $explicit,
    ) {
    }

    public static function fromString(string $value): self
    {
        self::assertValid($value);

        return new self($value, true);
    }

    public static function createDefaultForMessage(string $messageId): self
    {
        self::assertValid('wp-'.$messageId);

        return new self('wp-'.$messageId, false);
    }

    /** Chosen by the application, as opposed to derived from the message id. */
    public function isExplicit(): bool
    {
        return $this->explicit;
    }

    public function toString(): string
    {
        return $this->value;
    }

    private static function assertValid(string $value): void
    {
        if (1 !== preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $value)) {
            throw InvalidValue::because('Cannot accept a tag that is not 1 to 64 characters among letters, digits and ._:-');
        }
    }
}
