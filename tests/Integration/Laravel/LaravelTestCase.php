<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use RomainMillan\WebPushNotification\Application\Port\PushTransport;
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent\EloquentSubscriptionRepository;
use RomainMillan\WebPushNotification\Bridge\Laravel\WebPushNotificationServiceProvider;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidKeyGenerator;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Support\RecordingPushTransport;

/**
 * A Laravel application with the package installed as an application would install
 * it: auto-discovered provider, published migration, SQLite in memory — and the
 * network replaced by a recording transport.
 */
abstract class LaravelTestCase extends TestCase
{
    protected const MIGRATIONS = __DIR__.'/../../../src/Bridge/Laravel/database/migrations';

    protected RecordingPushTransport $recordingPushTransport;

    /** @var array{publicKey: string, privateKey: string}|null */
    private static ?array $vapidKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordingPushTransport = new RecordingPushTransport();
        $this->app()->instance(PushTransport::class, $this->recordingPushTransport);
    }

    protected function getPackageProviders($app): array
    {
        return [WebPushNotificationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        self::configure($app, self::baseConfiguration());
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(self::MIGRATIONS);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function baseConfiguration(): array
    {
        self::$vapidKeys ??= (new VapidKeyGenerator())->generate();

        return [
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.url' => 'https://app.example',
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'web-push.vapid.public_key' => self::$vapidKeys['publicKey'],
            'web-push.vapid.private_key' => self::$vapidKeys['privateKey'],
            'web-push.vapid.subject' => 'mailto:ops@app.example',
            'web-push.service_worker.fallback_title' => 'Test app',
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    protected static function configure(Application $app, array $values): void
    {
        $app->make(Repository::class)->set($values);
    }

    protected function app(): Application
    {
        return $this->app ?? throw new \LogicException('There is no Laravel application booted.');
    }

    protected function repository(): EloquentSubscriptionRepository
    {
        return $this->app()->make(EloquentSubscriptionRepository::class);
    }

    protected function registerBrowser(Owner $owner, TestBrowser $browser): void
    {
        $this->app()->make(RegisterSubscription::class)->register($owner, $browser->address());
    }
}
