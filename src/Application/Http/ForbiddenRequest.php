<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Http;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** Missing or invalid CSRF token. Answered as 403. */
final class ForbiddenRequest extends \RuntimeException implements WebPushNotificationException
{
    public static function invalidCsrfToken(): self
    {
        return new self('Cannot accept a web push request without a valid CSRF token.');
    }
}
