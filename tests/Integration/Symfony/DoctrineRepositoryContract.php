<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\DoctrineSubscriptionRepository;
use RomainMillan\WebPushNotification\Testing\SubscriptionRepositoryContract;
use RomainMillan\WebPushNotification\Tests\Integration\Symfony\App\TestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The persistence contract on a real database — SQLite here, MySQL and PostgreSQL in the subclasses
 * (locking and unique-index behaviour cannot be proven on SQLite alone).
 */
abstract class DoctrineRepositoryContract extends SubscriptionRepositoryContract
{
    private TestKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel($this->environment(), true);
        $this->kernel->boot();

        $entityManager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        // SchemaTool::dropSchema() swallows failures: drop explicitly, so a leftover
        // table can never make the next test fail on "table already exists".
        $entityManager->getConnection()->executeStatement('DROP TABLE IF EXISTS web_push_subscription');
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        // Unset when a subclass skipped the test before booting (no MySQL / PostgreSQL DSN).
        if (!isset($this->kernel)) {
            return;
        }

        $this->kernel->shutdown();
    }

    protected function environment(): string
    {
        return 'test';
    }

    protected function repository(): DoctrineSubscriptionRepository
    {
        $repository = $this->testContainer()->get(DoctrineSubscriptionRepository::class);
        \assert($repository instanceof DoctrineSubscriptionRepository);

        return $repository;
    }

    protected function transactionBoundary(): TransactionBoundary
    {
        $boundary = $this->testContainer()->get(TransactionBoundary::class);
        \assert($boundary instanceof TransactionBoundary);

        return $boundary;
    }

    private function testContainer(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }
}
