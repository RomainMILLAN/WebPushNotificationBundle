<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Exception;

/**
 * The aggregate refused a transition that would break one of its invariants.
 *
 * Reaching this exception means a caller bypassed Subscription::claim(), the only
 * entry point that turns such a transition into a neutral refusal.
 */
final class InvariantViolated extends \LogicException implements WebPushNotificationException
{
    public static function possessionNotProven(): self
    {
        return new self('Cannot hand a subscription over without proof of possession of its auth secret.');
    }

    public static function ownerDowngrade(): self
    {
        return new self('Cannot hand an identified subscription over to an anonymous owner.');
    }

    public static function differentEndpoint(): self
    {
        return new self('Cannot claim a subscription with another endpoint than its own.');
    }

    public static function notActive(): self
    {
        return new self('Cannot apply this transition to a retired subscription.');
    }

    public static function alreadyActive(): self
    {
        return new self('Cannot reactivate a subscription that is still active.');
    }
}
