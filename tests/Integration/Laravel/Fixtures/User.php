<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures;

use Illuminate\Foundation\Auth\User as AuthenticatableModel;
use Illuminate\Notifications\Notifiable;

/** An application user that knows nothing about web push. */
final class User extends AuthenticatableModel
{
    use Notifiable;

    public static function withId(int $id): self
    {
        $user = new self();
        $user->forceFill(['id' => $id]);

        return $user;
    }
}
