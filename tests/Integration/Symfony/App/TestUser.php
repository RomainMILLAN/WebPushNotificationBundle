<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony\App;

use RomainMillan\WebPushNotification\Bridge\Symfony\Notifier\WebPushRecipientInterface;
use RomainMillan\WebPushNotification\Bridge\Symfony\Security\WebPushSubscriber;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TestUser implements UserInterface, WebPushSubscriber, WebPushRecipientInterface
{
    public function __construct(
        private int $id,
        private string $email,
    ) {
    }

    public function getWebPushSubscriberId(): string
    {
        return 'user:'.$this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return '' !== $this->email ? $this->email : 'anonymous';
    }
}
