<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Configuration;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** Thrown at boot: the deployment must fail, not the first push in production. */
final class InvalidConfiguration extends \InvalidArgumentException implements WebPushNotificationException
{
    public static function because(string $rule, ?\Throwable $previous = null): self
    {
        return new self($rule, 0, $previous);
    }
}
