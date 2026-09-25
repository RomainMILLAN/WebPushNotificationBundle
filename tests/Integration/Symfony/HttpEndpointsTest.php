<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Integration\Symfony\App\TestKernel;
use RomainMillan\WebPushNotification\Tests\Integration\Symfony\App\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class HttpEndpointsTest extends SymfonyTestCase
{
    #[Test]
    public function it_should_deny_an_anonymous_visitor_before_reading_the_body(): void
    {
        $client = $this->browser();
        $config = $this->pageConfig($client);

        $client->request('POST', $config['subscribe'], server: $this->headers($config), content: 'not even json');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function it_should_register_the_browser_of_the_logged_in_subscriber(): void
    {
        $client = $this->browser();
        $client->loginUser(new TestUser(42, 'alice@example.com'));
        $config = $this->pageConfig($client);
        $browser = TestBrowser::chrome();

        $client->request('POST', $config['subscribe'], server: $this->headers($config), content: json_encode($browser->toJson() + ['contentEncoding' => 'aes128gcm'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->service(SubscriptionRepository::class)->ownedBy(IdentifiedOwner::fromSubscriberId('user:42')));
    }

    #[Test]
    public function it_should_refuse_a_request_without_csrf_token(): void
    {
        $client = $this->browser();
        $client->loginUser(new TestUser(42, 'alice@example.com'));
        $config = $this->pageConfig($client);

        $client->request('POST', $config['subscribe'], server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(TestBrowser::chrome()->toJson(), \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function it_should_refuse_a_body_that_is_not_json(): void
    {
        $client = $this->browser();
        $client->loginUser(new TestUser(42, 'alice@example.com'));
        $config = $this->pageConfig($client);

        $client->request('POST', $config['subscribe'], server: ['CONTENT_TYPE' => 'text/plain'] + $this->headers($config), content: '{}');

        self::assertResponseStatusCodeSame(415);
    }

    #[Test]
    public function it_should_refuse_an_oversized_body(): void
    {
        $client = $this->browser();
        $client->loginUser(new TestUser(42, 'alice@example.com'));
        $config = $this->pageConfig($client);

        $client->request('POST', $config['subscribe'], server: $this->headers($config), content: '{"endpoint":"'.str_repeat('a', 5 * 1024).'"}');

        self::assertResponseStatusCodeSame(413);
    }

    #[Test]
    public function it_should_answer_the_same_neutral_204_when_the_matrix_refuses(): void
    {
        $client = $this->browser();
        $browser = TestBrowser::chrome();
        $client->loginUser(new TestUser(1, 'alice@example.com'));
        $config = $this->pageConfig($client);
        $client->request('POST', $config['subscribe'], server: $this->headers($config), content: json_encode($browser->toJson(), \JSON_THROW_ON_ERROR));

        $client->loginUser(new TestUser(666, 'mallory@example.com'));
        $config = $this->pageConfig($client);
        $forged = $browser->toJson();
        $forged['keys']['auth'] = 'AAAAAAAAAAAAAAAAAAAAAA';
        $client->request('POST', $config['subscribe'], server: $this->headers($config), content: json_encode($forged, \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->service(SubscriptionRepository::class)->ownedBy(IdentifiedOwner::fromSubscriberId('user:1')));
    }

    #[Test]
    public function it_should_let_the_device_unsubscribe_by_proving_possession_even_logged_out(): void
    {
        $client = $this->browser();
        $browser = TestBrowser::chrome();
        $client->loginUser(new TestUser(42, 'alice@example.com'));
        $config = $this->pageConfig($client);
        $client->request('POST', $config['subscribe'], server: $this->headers($config), content: json_encode($browser->toJson(), \JSON_THROW_ON_ERROR));

        $client->request('POST', $config['unsubscribe'], server: $this->headers($config), content: json_encode(['endpoint' => $browser->endpoint(), 'keys' => ['auth' => 'AAAAAAAAAAAAAAAAAAAAAA']], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->service(SubscriptionRepository::class)->ownedBy(IdentifiedOwner::fromSubscriberId('user:42')));

        $client->request('POST', $config['unsubscribe'], server: $this->headers($config), content: json_encode(['endpoint' => $browser->endpoint(), 'keys' => ['auth' => $browser->auth()]], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);
        self::assertCount(0, $this->service(SubscriptionRepository::class)->ownedBy(IdentifiedOwner::fromSubscriberId('user:42')));
    }

    #[Test]
    public function it_should_serve_a_stateless_service_worker_script(): void
    {
        $client = $this->browser();

        $client->request('GET', '/web-push-sw.js');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/javascript; charset=utf-8');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseNotHasCookie('MOCKSESSID');
        self::assertStringStartsWith('self.__WEB_PUSH_CONFIG__ = {', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('"clickPrefixes":["/app/"]', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function it_should_mark_a_page_carrying_the_meta_tag_private(): void
    {
        $client = $this->browser();

        $client->request('GET', '/page');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    #[Test]
    public function it_should_accept_anonymous_visitors_when_opted_in_up_to_the_cap(): void
    {
        $client = $this->browser('anonymous');
        $config = $this->pageConfig($client);

        foreach (['a', 'b', 'c'] as $token) {
            $client->request('POST', $config['subscribe'], server: $this->headers($config), content: json_encode(TestBrowser::chrome($token)->toJson(), \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(204);
        }

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        self::assertEquals(2, $connection->fetchOne('SELECT COUNT(*) FROM web_push_subscription'));
    }

    #[Test]
    public function it_should_answer_429_to_the_31st_unsubscription_within_a_minute(): void
    {
        $client = $this->browser();
        $config = $this->pageConfig($client);
        $unsubscription = json_encode(['endpoint' => TestBrowser::chrome()->endpoint(), 'keys' => ['auth' => 'AAAAAAAAAAAAAAAAAAAAAA']], \JSON_THROW_ON_ERROR);

        for ($attempt = 1; $attempt <= 30; ++$attempt) {
            $client->request('POST', $config['unsubscribe'], server: $this->headers($config), content: $unsubscription);
            self::assertResponseStatusCodeSame(204);
        }
        $client->request('POST', $config['unsubscribe'], server: $this->headers($config), content: $unsubscription);

        self::assertResponseStatusCodeSame(429);
    }

    #[Test]
    public function it_should_keep_the_unsubscribe_limiter_declared_by_the_application(): void
    {
        TestKernel::$extraFrameworkConfig = ['rate_limiter' => ['web_push_unsubscribe' => ['policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute']]];
        $client = $this->browser();
        $config = $this->pageConfig($client);
        $unsubscription = json_encode(['endpoint' => TestBrowser::chrome()->endpoint(), 'keys' => ['auth' => 'AAAAAAAAAAAAAAAAAAAAAA']], \JSON_THROW_ON_ERROR);

        $client->request('POST', $config['unsubscribe'], server: $this->headers($config), content: $unsubscription);
        $client->request('POST', $config['unsubscribe'], server: $this->headers($config), content: $unsubscription);

        self::assertResponseStatusCodeSame(429);
    }

    #[Test]
    public function it_should_point_the_page_at_the_package_service_worker_by_default(): void
    {
        $client = $this->browser();

        self::assertSame('/web-push-sw.js', $this->pageConfig($client)['serviceWorker']);
    }

    #[Test]
    public function it_should_point_the_page_at_the_application_service_worker_and_still_serve_the_package_one(): void
    {
        TestKernel::$extraConfig = ['service_worker' => ['register_url' => '/sw.js']];
        $client = $this->browser();

        self::assertSame('/sw.js', $this->pageConfig($client)['serviceWorker']);

        $client->request('GET', '/web-push-sw.js');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('self.__WEB_PUSH_CONFIG__ = {', (string) $client->getResponse()->getContent());
    }

    /**
     * @return array{serviceWorker: string, subscribe: string, unsubscribe: string, csrfHeader: string, csrfToken: string, clientState: string}
     */
    private function pageConfig(KernelBrowser $client): array
    {
        $crawler = $client->request('GET', '/page');
        $config = json_decode((string) $crawler->filter('meta[name="web-push-config"]')->attr('content'), true, 8, \JSON_THROW_ON_ERROR);
        \assert(\is_array($config));

        /** @var array{serviceWorker: string, subscribe: string, unsubscribe: string, csrfHeader: string, csrfToken: string, clientState: string} $config */
        return $config;
    }

    /**
     * @param array{csrfHeader: string, csrfToken: string} $config
     *
     * @return array<string, string>
     */
    private function headers(array $config): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_'.strtoupper(str_replace('-', '_', $config['csrfHeader'])) => $config['csrfToken']];
    }
}
