<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Exception;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** Deny by default: no authenticated subscriber and anonymous subscriptions are off. */
final class AnonymousSubscriptionsDisabled extends \RuntimeException implements WebPushNotificationException
{
    public static function create(): self
    {
        return new self('Cannot subscribe without an authenticated subscriber: anonymous subscriptions are disabled.');
    }
}
