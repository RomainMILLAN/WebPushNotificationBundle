<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Http;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** Answered as 429. */
final class TooManyRequests extends \RuntimeException implements WebPushNotificationException
{
    public static function create(): self
    {
        return new self('Cannot accept more web push requests from this client for now.');
    }
}
