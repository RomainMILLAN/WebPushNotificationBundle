<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * What a registration did. Refusals carry an internal reason for logs and metrics;
 * the HTTP answer stays neutral whatever the reason.
 */
enum ClaimOutcome: string
{
    case Registered = 'registered';
    case Renewed = 'renewed';
    case Reassigned = 'reassigned';
    case Reactivated = 'reactivated';
    case RefusedHostNotAllowed = 'refused_host_not_allowed';
    case RefusedAuthMismatch = 'refused_auth_mismatch';
    case RefusedDowngrade = 'refused_downgrade';
    case RefusedAnonymousCap = 'refused_anonymous_cap';

    public function isRefused(): bool
    {
        return match ($this) {
            self::RefusedHostNotAllowed, self::RefusedAuthMismatch, self::RefusedDowngrade, self::RefusedAnonymousCap => true,
            default => false,
        };
    }

    /** A device that newly counts for its owner must fit in the owner's quota. */
    public function requiresQuota(): bool
    {
        return match ($this) {
            self::Registered, self::Reassigned, self::Reactivated => true,
            default => false,
        };
    }
}
