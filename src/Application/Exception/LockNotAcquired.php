<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Exception;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** Answered as a neutral 503: retrying later is always safe. */
final class LockNotAcquired extends \RuntimeException implements WebPushNotificationException
{
    public static function create(): self
    {
        return new self('Cannot acquire the subscription lock in time.');
    }
}
