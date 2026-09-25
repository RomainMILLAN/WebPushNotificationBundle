<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Exception;

/**
 * Marker implemented by every exception the package throws on purpose.
 *
 * Controllers catch THIS type only: anything else is a bug and must surface as one,
 * never be swallowed into a mute 400.
 */
interface WebPushNotificationException extends \Throwable
{
}
