<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\DependencyInjection;

use Psr\Log\NullLogger;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionLock;
use RomainMillan\WebPushNotification\Bridge\Symfony\Lock\SymfonyLockSubscriptionLock;
use RomainMillan\WebPushNotification\Bridge\Symfony\Messenger\SendWebPushHandler;
use RomainMillan\WebPushNotification\Bridge\Symfony\Notifier\WebPushChannel;
use RomainMillan\WebPushNotification\Bridge\Symfony\Twig\WebPushExtension;
use RomainMillan\WebPushNotification\Infrastructure\Clock\SystemClock;
use RomainMillan\WebPushNotification\Infrastructure\InMemory\LocalSubscriptionLock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Integrates with what the application actually installed — decisions that can only
 * be taken once every extension has loaded.
 */
final readonly class FrameworkIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SendWebPushHandler::class)) {
            return;
        }

        $this->useFrameworkServiceOr($container, 'web_push_notification.clock', 'clock', SystemClock::class);
        $this->useFrameworkServiceOr($container, 'web_push_notification.logger', 'logger', NullLogger::class);

        $configured = $container->getParameter('web_push_notification.lock_factory');
        $lockFactory = \is_string($configured) ? $configured : ($container->has('lock.factory') ? 'lock.factory' : null);

        if (null !== $lockFactory) {
            $container->setDefinition(SubscriptionLock::class, new Definition(SymfonyLockSubscriptionLock::class, [new Reference($lockFactory)]));
        } else {
            $container->setDefinition(SubscriptionLock::class, new Definition(LocalSubscriptionLock::class));
        }

        // Optional integrations: drop what the application did not install.
        if (!$container->has('twig')) {
            $container->removeDefinition(WebPushExtension::class);
        }

        if (!$container->has('notifier')) {
            $container->removeDefinition(WebPushChannel::class);
        }
    }

    private function useFrameworkServiceOr(ContainerBuilder $container, string $id, string $frameworkId, string $fallbackClass): void
    {
        if ($container->has($frameworkId)) {
            $container->setAlias($id, $frameworkId);

            return;
        }

        $container->setDefinition($id, new Definition($fallbackClass));
    }
}
