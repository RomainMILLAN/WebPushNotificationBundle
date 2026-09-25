<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Exception;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** Answered as 415. */
final class UnsupportedMediaType extends \InvalidArgumentException implements WebPushNotificationException
{
    public static function create(): self
    {
        return new self('Cannot accept a request body that is not application/json.');
    }
}
