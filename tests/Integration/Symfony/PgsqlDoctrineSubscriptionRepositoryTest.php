<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony;

final class PgsqlDoctrineSubscriptionRepositoryTest extends DoctrineRepositoryContract
{
    protected function setUp(): void
    {
        if (false === getenv('WEB_PUSH_TEST_PGSQL_DSN') || '' === getenv('WEB_PUSH_TEST_PGSQL_DSN')) {
            self::markTestSkipped('WEB_PUSH_TEST_PGSQL_DSN is not set.');
        }

        parent::setUp();
    }

    protected function environment(): string
    {
        return 'pgsql';
    }
}
