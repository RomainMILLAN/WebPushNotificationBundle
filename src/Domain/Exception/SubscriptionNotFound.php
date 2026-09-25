<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Exception;

final class SubscriptionNotFound extends \RuntimeException implements WebPushNotificationException
{
    public static function withId(string $id): self
    {
        return new self(\sprintf('There is no subscription %s.', $id));
    }
}
