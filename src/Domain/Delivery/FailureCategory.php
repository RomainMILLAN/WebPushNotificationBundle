<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

enum FailureCategory: string
{
    case None = 'none';
    /** 404/410: the subscription no longer exists. */
    case Gone = 'gone';
    /** 400/413: the push service rejected the payload. */
    case Payload = 'payload';
    /** 401/403: VAPID mismatch — typically after a key rotation. Never expires the subscription. */
    case Vapid = 'vapid';
    /** 429. */
    case RateLimited = 'rate_limited';
    /** 5xx. */
    case Server = 'server';
    /** Timeout, DNS, TLS, connection. */
    case Network = 'network';
    /** DNS resolved to a non-public address, or pinning was impossible. */
    case Unsafe = 'unsafe';
    /** Any other unexpected status. */
    case Unexpected = 'unexpected';
    /** Skipped: the subscription was retired or deleted. */
    case NotActive = 'not_active';
    /** Skipped: the subscription changed hands since the message was dispatched. */
    case OwnerChanged = 'owner_changed';
    /** Skipped: the host is no longer an allowed push service. */
    case HostNotAllowed = 'host_not_allowed';
}
