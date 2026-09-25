<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Exception;

/**
 * A value object refused its input.
 *
 * The message describes the rule, never the rejected value: the value may be a
 * secret (subscription key) or attacker-controlled text.
 */
final class InvalidValue extends \InvalidArgumentException implements WebPushNotificationException
{
    public static function because(string $rule): self
    {
        return new self($rule);
    }
}
