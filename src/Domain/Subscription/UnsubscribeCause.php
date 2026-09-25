<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

enum UnsubscribeCause: string
{
    /** The authenticated owner removed it. */
    case Owner = 'owner';

    /** The device itself proved possession of the auth secret (logout, anonymous). */
    case Possession = 'possession';

    /** The owner revoked it from a device list. */
    case Revoked = 'revoked';

    /** The subscriber account was deleted. */
    case AccountRemoved = 'account_removed';
}
