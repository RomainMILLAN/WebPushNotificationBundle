<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use RomainMillan\WebPushNotification\Application\Port\PushTransport;
use RomainMillan\WebPushNotification\Bridge\Symfony\Messenger\SendWebPush;
use RomainMillan\WebPushNotification\Bridge\Symfony\WebPushNotificationBundle;
use RomainMillan\WebPushNotification\Tests\Support\VapidKeys;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Environment;

/**
 * Environments select a variant: "test" (immediate delivery), "messenger",
 * "anonymous", "mysql", "pgsql". extraConfig / extraFrameworkConfig let a test override the
 * package / framework configuration.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @var array<string, mixed> */
    public static array $extraConfig = [];

    /** @var array<string, mixed> */
    public static array $extraFrameworkConfig = [];

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SecurityBundle(), new TwigBundle(), new DoctrineBundle(), new WebPushNotificationBundle()];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return __DIR__.'/var/cache/'.$this->environment.'/'.md5((string) json_encode([self::$extraConfig, self::$extraFrameworkConfig]));
    }

    public function renderPage(Environment $twig): Response
    {
        return new Response($twig->render('page.html.twig'));
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', array_replace_recursive([
            'secret' => 'test-secret',
            'test' => true,
            'http_method_override' => false,
            'csrf_protection' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'router' => ['utf8' => true],
            'rate_limiter' => ['web_push' => ['policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 minute']],
            'lock' => 'flock://'.__DIR__.'/var/lock',
            'messenger' => [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [SendWebPush::class => 'async'],
            ],
            'notifier' => ['channel_policy' => ['urgent' => ['web_push']]],
            'property_access' => true,
        ], self::$extraFrameworkConfig));

        $container->extension('security', [
            'providers' => ['test' => ['id' => TestUserProvider::class]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'test']],
        ]);

        $container->extension('twig', ['default_path' => __DIR__.'/templates']);

        $container->extension('doctrine', [
            'dbal' => $this->database(),
            // auto_mapping as in the doctrine/orm recipe: the bundle must not break it.
            'orm' => ['auto_mapping' => true, 'controller_resolver' => ['auto_mapping' => false]]
                // As in the doctrine/orm recipe: symfony/var-exporter 8 (PHP >= 8.4) no longer ships
                // the LazyGhost proxies of the ORM, native lazy objects replace them.
                + (\PHP_VERSION_ID >= 80400 ? ['enable_native_lazy_objects' => true] : []),
        ]);

        $container->extension('web_push_notification', array_replace_recursive([
            'vapid' => $this->vapid() + ['subject' => 'mailto:ops@example.com'],
            'delivery' => ['dispatcher' => 'messenger' === $this->environment ? 'messenger' : 'immediate', 'dns_pinning' => false],
            'anonymous' => 'anonymous' === $this->environment ? ['enabled' => true, 'rate_limiter' => 'web_push', 'max_active' => 2] : [],
            'service_worker' => ['prebuilt_path' => \dirname(__DIR__, 3).'/Fixtures/web-push-sw.stub.js', 'click_prefixes' => ['/app/']],
        ], self::$extraConfig));

        $container->services()->set(TestUserProvider::class);
        $container->services()->alias('test.notifier', 'notifier')->public();
        $container->services()->set(RecordingTransport::class)->public();
        $container->services()->alias(PushTransport::class, RecordingTransport::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@WebPushNotificationBundle/config/routes.php');
        $routes->add('page', '/page')->controller([self::class, 'renderPage']);
    }

    /**
     * @return array{url: string, server_version?: string}
     */
    private function database(): array
    {
        return match ($this->environment) {
            'mysql' => ['url' => (string) getenv('WEB_PUSH_TEST_MYSQL_DSN')],
            // DoctrineBundle needs the server version to pick the platform without connecting.
            'pgsql' => ['url' => (string) getenv('WEB_PUSH_TEST_PGSQL_DSN'), 'server_version' => '17'],
            default => ['url' => 'sqlite:///'.__DIR__.'/var/'.$this->environment.'.db'],
        };
    }

    /**
     * @return array{public_key: string, private_key: string}
     */
    private function vapid(): array
    {
        return ['public_key' => VapidKeys::pair()['publicKey'], 'private_key' => VapidKeys::pair()['privateKey']];
    }
}
