<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony;

use RomainMillan\WebPushNotification\Application\Port\DeliveryOutcomeListener;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerConfig;
use RomainMillan\WebPushNotification\Bridge\Symfony\DependencyInjection\FrameworkIntegrationPass;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Inheritance imposed by the HttpKernel bundle extension point.
 *
 * Static configuration (hosts, quotas, service worker settings) is validated when the
 * container is built; secrets (VAPID keys, encryption keys) are usually env vars and
 * are validated when their services are first instantiated.
 */
final class WebPushNotificationBundle extends AbstractBundle
{
    private const UNSUBSCRIBE_RATE_LIMITER = 'web_push_unsubscribe';

    protected string $extensionAlias = 'web_push_notification';

    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('config/definition.php');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->prependUnsubscribeRateLimiter($builder);

        if (!$builder->hasExtension('doctrine')) {
            return;
        }

        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'WebPushNotification' => [
                        'type' => 'xml',
                        'is_bundle' => false,
                        // Not config/doctrine: DoctrineBundle's auto_mapping scans that
                        // directory with a "<bundle namespace>\Entity" prefix and would
                        // register a second, broken mapping of the same file.
                        'dir' => __DIR__.'/config/orm-mapping',
                        'prefix' => 'RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine',
                        'alias' => 'WebPushNotification',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!class_exists(RateLimiterFactory::class)) {
            throw new \LogicException('Cannot build the web push routes without symfony/rate-limiter: the unsubscribe endpoint is always rate limited. Run "composer require symfony/rate-limiter" and enable framework.rate_limiter.');
        }

        /** @var array{push_services: array{extra_hosts: list<string>}, service_worker: array{fallback_title: string, icon: string, badge: string, click_prefixes: list<string>, asset_hosts: list<string>, state_cache: string}, lock: array{factory: ?string}} $config */
        AllowedPushServices::createWithKnownServices($config['push_services']['extra_hosts']);

        $serviceWorker = $config['service_worker'];
        ServiceWorkerConfig::fromSettings($serviceWorker['fallback_title'], $serviceWorker['icon'], $serviceWorker['badge'], $serviceWorker['click_prefixes'], $serviceWorker['asset_hosts'], $serviceWorker['state_cache']);

        $builder->setParameter('web_push_notification.lock_factory', $config['lock']['factory']);
        $builder->registerForAutoconfiguration(DeliveryOutcomeListener::class)->addTag(DeliveryOutcomeListener::class);

        /** @var \Closure(ContainerConfigurator, array<string, mixed>): void $services */
        $services = require __DIR__.'/config/services.php';
        $services($container, $config);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new FrameworkIntegrationPass());
    }

    /**
     * The default unsubscribe limiter (sliding window, 30 per minute per client), unless
     * the application names its own or already declares one with that name. Without
     * the component, nothing is prepended: loadExtension() explains what is missing.
     */
    private function prependUnsubscribeRateLimiter(ContainerBuilder $builder): void
    {
        if (!class_exists(RateLimiterFactory::class) || !$builder->hasExtension('framework')) {
            return;
        }

        foreach ($builder->getExtensionConfig($this->extensionAlias) as $packageConfig) {
            if (\is_array($packageConfig['routes'] ?? null) && null !== ($packageConfig['routes']['unsubscribe_rate_limiter'] ?? null)) {
                return;
            }
        }

        $usesLock = false;
        foreach ($builder->getExtensionConfig('framework') as $frameworkConfig) {
            $limiters = \is_array($frameworkConfig['rate_limiter'] ?? null) ? $frameworkConfig['rate_limiter'] : [];
            $declared = \is_array($limiters['limiters'] ?? null) ? $limiters['limiters'] + $limiters : $limiters;

            if (\array_key_exists(self::UNSUBSCRIBE_RATE_LIMITER, $declared)) {
                return;
            }

            $usesLock = $usesLock || (isset($frameworkConfig['lock']) && false !== $frameworkConfig['lock']);
        }

        $limiter = ['policy' => 'sliding_window', 'limit' => 30, 'interval' => '1 minute'];

        // Symfony 6.4 defaults lock_factory to lock.factory and fails when Lock is not configured.
        $builder->prependExtensionConfig('framework', ['rate_limiter' => [self::UNSUBSCRIBE_RATE_LIMITER => $usesLock ? $limiter : $limiter + ['lock_factory' => null]]]);
    }
}
