<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\InvalidConfiguration;
use RomainMillan\WebPushNotification\Domain\Message\Origin;

/** A development application on plain http, with an explicit VAPID subject. */
final class HttpAppUrlTest extends LaravelTestCase
{
    #[Test]
    public function it_should_boot_and_serve_the_worker_with_an_http_app_url(): void
    {
        $response = $this->get('/web-push-sw.js');

        $response->assertOk();
    }

    #[Test]
    public function it_should_answer_on_the_subscription_routes_with_an_http_app_url(): void
    {
        $response = $this->call('POST', '/web-push/subscriptions', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}');

        $response->assertForbidden();
    }

    #[Test]
    public function it_should_refuse_to_resolve_the_origin_of_an_http_app_url(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Cannot resolve the web push origin from APP_URL "http://myapp.test"');

        $this->app()->make(Origin::class);
    }

    #[Test]
    public function it_should_refuse_to_sign_action_urls_for_an_http_app_url(): void
    {
        $this->expectException(InvalidConfiguration::class);

        $this->app()->make(ActionUrlSigner::class);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        self::configure($app, ['app.url' => 'http://myapp.test', 'web-push.vapid.subject' => 'mailto:ops@example.com']);
    }
}
