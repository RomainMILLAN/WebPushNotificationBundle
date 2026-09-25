<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

/** web-push.subscriber.resolver configured as a static callable. */
final readonly class CallableSubscriberIdResolver implements SubscriberIdResolver
{
    public function __construct(
        private \Closure $resolver,
    ) {
    }

    public function resolveSubscriberId(Authenticatable $user): SubscriberId
    {
        $subscriberId = ($this->resolver)($user);

        if (!\is_string($subscriberId)) {
            throw UnresolvableSubscriber::because('Cannot use a web-push.subscriber.resolver that does not return a string.');
        }

        try {
            return SubscriberId::fromString($subscriberId);
        } catch (InvalidValue $invalid) {
            throw UnresolvableSubscriber::because('Cannot use this subscriber id: '.$invalid->getMessage(), $invalid);
        }
    }
}
