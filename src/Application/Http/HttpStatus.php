<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Http;

use RomainMillan\WebPushNotification\Application\Exception\AnonymousSubscriptionsDisabled;
use RomainMillan\WebPushNotification\Application\Exception\LockNotAcquired;
use RomainMillan\WebPushNotification\Application\Exception\RequestTooLarge;
use RomainMillan\WebPushNotification\Application\Exception\UnsupportedMediaType;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/**
 * One mapping for both bridges. Bodies stay empty: the client learns the status,
 * never why — no oracle, no echo of attacker input.
 */
final readonly class HttpStatus
{
    public const NEUTRAL = 204;

    public static function forFailure(WebPushNotificationException $failure): int
    {
        return match (true) {
            $failure instanceof AnonymousSubscriptionsDisabled, $failure instanceof ForbiddenRequest => 403,
            $failure instanceof UnsupportedMediaType => 415,
            $failure instanceof RequestTooLarge => 413,
            $failure instanceof TooManyRequests => 429,
            $failure instanceof LockNotAcquired => 503,
            default => 400,
        };
    }
}
