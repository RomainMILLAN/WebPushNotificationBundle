<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * The application identity that receives notifications, e.g. "user:42".
 *
 * It MUST be stable and never reused: if it were an e-mail address, a user changing
 * address and another one signing up with the old one would inherit each other's
 * devices. The bridges therefore never derive it from UserInterface::getUserIdentifier().
 */
final readonly class SubscriberId
{
    public const MAX_LENGTH = 191;

    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $length = u($value)->length();

        if (0 === $length || $length > self::MAX_LENGTH) {
            throw InvalidValue::because(\sprintf('Cannot accept a subscriber id that is empty or longer than %d characters.', self::MAX_LENGTH));
        }

        // Printable, no whitespace: it becomes a lock key and a database value.
        if (1 !== preg_match('/^[\x21-\x7E]+$/D', $value)) {
            throw InvalidValue::because('Cannot accept a subscriber id carrying characters other than printable ASCII without whitespace.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
