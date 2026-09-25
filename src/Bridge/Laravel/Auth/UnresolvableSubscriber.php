<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Auth;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** A misconfiguration, surfaced as a server error — never a neutral answer. */
final class UnresolvableSubscriber extends \LogicException implements WebPushNotificationException
{
    public static function because(string $rule, ?\Throwable $previous = null): self
    {
        return new self($rule, 0, $previous);
    }
}
