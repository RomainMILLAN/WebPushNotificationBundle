<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;

/**
 * Default resolver: WebPushSubscriber when implemented, else the auth identifier
 * prefixed by the morph alias (or the model class) — "user:42", "App\Models\User:42".
 *
 * Namespaced so that two user providers never collide, and never derived from a
 * mutable attribute such as the e-mail address.
 */
final readonly class ModelSubscriberIdResolver implements SubscriberIdResolver
{
    public function resolveSubscriberId(Authenticatable $user): SubscriberId
    {
        try {
            return SubscriberId::fromString($user instanceof WebPushSubscriber ? $user->getWebPushSubscriberId() : $this->namespacedIdentifierOf($user));
        } catch (InvalidValue $invalid) {
            throw UnresolvableSubscriber::because('Cannot use this subscriber id: '.$invalid->getMessage(), $invalid);
        }
    }

    private function namespacedIdentifierOf(Authenticatable $user): string
    {
        $identifier = $user->getAuthIdentifier();

        if (!\is_int($identifier) && (!\is_string($identifier) || '' === $identifier)) {
            throw UnresolvableSubscriber::because('Cannot derive a web push subscriber id from a user without a scalar auth identifier: implement WebPushSubscriber.');
        }

        $namespace = $user instanceof Model ? Relation::getMorphAlias($user::class) : $user::class;

        return $namespace.':'.$identifier;
    }
}
