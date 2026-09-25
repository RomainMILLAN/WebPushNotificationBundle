<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use RomainMillan\WebPushNotification\Tests\Integration\Symfony\App\RecordingTransport;
use RomainMillan\WebPushNotification\Tests\Integration\Symfony\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\CacheClearer\Psr6CacheClearer;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class SymfonyTestCase extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(\is_string($options['environment'] ?? null) ? $options['environment'] : 'test', true);
    }

    protected function tearDown(): void
    {
        TestKernel::$extraConfig = [];
        TestKernel::$extraFrameworkConfig = [];
        parent::tearDown();
    }

    protected function browser(string $environment = 'test'): KernelBrowser
    {
        $client = static::createClient(['environment' => $environment]);
        $client->disableReboot();
        $this->createSchema();
        // Rate limiter state survives in cache.app from one test (and one run) to the next.
        $cacheClearer = static::getContainer()->get('cache.global_clearer');
        \assert($cacheClearer instanceof Psr6CacheClearer);
        $cacheClearer->clear('');

        return $client;
    }

    protected function createSchema(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->getConnection()->executeStatement('DROP TABLE IF EXISTS web_push_subscription');
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected static function bootedKernel(): KernelInterface
    {
        \assert(static::$kernel instanceof KernelInterface);

        return static::$kernel;
    }

    protected function transport(): RecordingTransport
    {
        $transport = static::getContainer()->get(RecordingTransport::class);
        \assert($transport instanceof RecordingTransport);

        return $transport;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
