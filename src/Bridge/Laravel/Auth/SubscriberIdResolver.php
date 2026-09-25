<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

/** Turns an authenticated user into its stable web push identity. */
interface SubscriberIdResolver
{
    /**
     * @throws UnresolvableSubscriber
     */
    public function resolveSubscriberId(Authenticatable $user): SubscriberId;
}
