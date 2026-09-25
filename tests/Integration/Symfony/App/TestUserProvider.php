<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony\App;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<TestUser>
 */
final class TestUserProvider implements UserProviderInterface
{
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof TestUser) {
            throw new UnsupportedUserException('Cannot refresh this user.');
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return TestUser::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return new TestUser(1, $identifier);
    }
}
