<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Exception;

/**
 * A concurrent registration inserted the same endpoint first.
 *
 * Adapters translate their unique-constraint violation into this exception;
 * RegisterSubscription replays once as an upsert.
 */
final class SubscriptionAlreadyExists extends \RuntimeException implements WebPushNotificationException
{
    public static function forFingerprint(string $shortFingerprint, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('There is already a subscription with fingerprint %s.', $shortFingerprint), 0, $previous);
    }
}
