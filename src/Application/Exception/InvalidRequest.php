<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Exception;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** Answered as a mute 400: the message is for logs, never echoed to the client. */
final class InvalidRequest extends \InvalidArgumentException implements WebPushNotificationException
{
    public static function because(string $rule): self
    {
        return new self($rule);
    }
}
