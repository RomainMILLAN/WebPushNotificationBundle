<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * Why a subscription left service. An explicit unsubscription is not a retirement:
 * it deletes the subscription.
 */
enum RetirementReason: string
{
    /** The push service answered 404/410. */
    case Expired = 'expired';

    /** The owner's quota made room for a newer device. */
    case Evicted = 'evicted';
}
