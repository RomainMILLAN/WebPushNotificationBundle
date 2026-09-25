<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionCipher;
use RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent\DbTransactionBoundary;
use RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent\EloquentSubscriptionRepository;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\NullSubscriptionCipher;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;

/**
 * A bare Laravel application (no package provider) holding the published migration
 * on one connection: what the repository contract runs against.
 */
final readonly class EloquentStorage
{
    private const CONNECTION = 'web_push';

    private const PGSQL_SCHEMA = 'web_push_eloquent';

    private function __construct(
        private LaravelApplication $application,
    ) {
    }

    public static function createInMemorySqlite(): self
    {
        return self::createWithConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    }

    public static function createFromMysqlDsn(string $dsn): self
    {
        return self::createWithConnection(['driver' => 'mysql', 'url' => $dsn, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']);
    }

    /**
     * In its own schema: the Doctrine suite drops and creates the same table in
     * "public" of the same database.
     */
    public static function createFromPgsqlDsn(string $dsn): self
    {
        return self::createWithConnection(['driver' => 'pgsql', 'url' => $dsn, 'charset' => 'utf8', 'prefix' => '', 'search_path' => self::PGSQL_SCHEMA], 'CREATE SCHEMA IF NOT EXISTS '.self::PGSQL_SCHEMA);
    }

    /**
     * @param array<string, mixed> $connection
     */
    private static function createWithConnection(array $connection, string $preparation = ''): self
    {
        $application = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
        $application->make(Repository::class)->set([
            'database.default' => self::CONNECTION,
            'database.connections.'.self::CONNECTION => $connection,
        ]);

        if ('' !== $preparation) {
            $application->make(DatabaseManager::class)->connection(self::CONNECTION)->statement($preparation);
        }

        Schema::dropIfExists('web_push_subscription');
        $migration = require __DIR__.'/../../../src/Bridge/Laravel/database/migrations/2026_01_01_000000_create_web_push_subscriptions_table.php';
        \assert($migration instanceof Migration && method_exists($migration, 'up'));
        $migration->up();

        return new self($application);
    }

    public function repository(SubscriptionCipher $subscriptionCipher = new NullSubscriptionCipher()): EloquentSubscriptionRepository
    {
        return new EloquentSubscriptionRepository(new SubscriptionRowMapper($subscriptionCipher), AllowedPushServices::createWithKnownServices(), self::CONNECTION);
    }

    public function transactionBoundary(): DbTransactionBoundary
    {
        return new DbTransactionBoundary($this->application->make(DatabaseManager::class)->connection(self::CONNECTION));
    }

    /** Laravel installs global error handlers at bootstrap: PHPUnit's are given back. */
    public function shutdownAfter(TestCase $testCase): void
    {
        // A shared server (MySQL, PostgreSQL) is left as found for the other suites.
        Schema::dropIfExists('web_push_subscription');
        $this->application->make(DatabaseManager::class)->disconnect(self::CONNECTION);
        $this->application->flush();
        HandleExceptions::flushState($testCase);
    }
}
