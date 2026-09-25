<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures;

use Illuminate\Foundation\Auth\User as AuthenticatableModel;
use Illuminate\Notifications\Notifiable;
use RomainMillan\WebPushNotification\Bridge\Laravel\Auth\WebPushSubscriber;

/** An application user naming its web push identity itself. */
final class Subscriber extends AuthenticatableModel implements WebPushSubscriber
{
    use Notifiable;

    public static function withId(int $id): self
    {
        $user = new self();
        $user->forceFill(['id' => $id]);

        return $user;
    }

    public function getWebPushSubscriberId(): string
    {
        $id = $this->getAuthIdentifier();

        return 'user:'.(\is_int($id) ? $id : 0);
    }
}
