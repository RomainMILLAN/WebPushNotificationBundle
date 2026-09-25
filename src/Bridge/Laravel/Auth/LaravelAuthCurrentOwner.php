<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Auth;

use Illuminate\Contracts\Auth\Factory;
use RomainMillan\WebPushNotification\Application\Port\CurrentOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;

/**
 * Who is on the other side of the request. It never decides whether anonymous
 * visitors are allowed: AnonymousGate, which decorates it, does.
 */
final readonly class LaravelAuthCurrentOwner implements CurrentOwner
{
    /**
     * @param string $guard '' for the default guard
     */
    public function __construct(
        private Factory $factory,
        private SubscriberIdResolver $subscriberIdResolver,
        private string $guard,
    ) {
    }

    public function resolve(): Owner
    {
        $user = $this->factory->guard('' === $this->guard ? null : $this->guard)->user();

        return null === $user ? new AnonymousOwner() : new IdentifiedOwner($this->subscriberIdResolver->resolveSubscriberId($user));
    }
}
