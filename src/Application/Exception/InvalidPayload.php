<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Exception;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

final class InvalidPayload extends \InvalidArgumentException implements WebPushNotificationException
{
    public static function tooLarge(int $bytes, int $max): self
    {
        return new self(\sprintf('Cannot send a payload of %d bytes: the limit is %d bytes.', $bytes, $max));
    }

    public static function notContractV1(): self
    {
        return new self('Cannot restore a payload that is not a contract v1 JSON object.');
    }
}
