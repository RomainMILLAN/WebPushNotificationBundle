<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class ServiceWorkerRouteTest extends LaravelTestCase
{
    #[Test]
    public function it_should_serve_the_prebuilt_worker_with_its_configuration_injected(): void
    {
        $response = $this->get('/web-push-sw.js');

        $response->assertOk();
        self::assertStringStartsWith('self.__WEB_PUSH_CONFIG__ = {', (string) $response->baseResponse->getContent());
        self::assertStringContainsString('"fallbackTitle":"Test app"', (string) $response->baseResponse->getContent());
    }

    #[Test]
    public function it_should_serve_javascript_that_browsers_must_not_sniff(): void
    {
        $response = $this->get('/web-push-sw.js');

        $response->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function it_should_serve_the_worker_without_session_nor_cookie(): void
    {
        $response = $this->get('/web-push-sw.js');

        $response->assertHeaderMissing('Set-Cookie');
        self::assertSame([], $response->headers->getCookies());
    }

    #[Test]
    public function it_should_not_widen_the_scope_of_a_worker_served_at_the_root(): void
    {
        $response = $this->get('/web-push-sw.js');

        $response->assertHeaderMissing('Service-Worker-Allowed');
    }

    #[Test]
    #[DefineEnvironment('serveTheWorkerBelowTheRoot')]
    public function it_should_allow_the_root_scope_to_a_worker_served_below_the_root(): void
    {
        $response = $this->get('/build/web-push-sw.js');

        $response->assertHeader('Service-Worker-Allowed', '/');
    }

    #[Test]
    #[DefineEnvironment('registerTheApplicationWorker')]
    public function it_should_still_serve_the_package_worker_when_the_application_registers_its_own(): void
    {
        $response = $this->get('/web-push-sw.js');

        $response->assertOk();
        self::assertStringStartsWith('self.__WEB_PUSH_CONFIG__ = {', (string) $response->baseResponse->getContent());
    }

    protected function registerTheApplicationWorker(Application $app): void
    {
        self::configure($app, ['web-push.service_worker.register_url' => '/sw.js']);
    }

    protected function serveTheWorkerBelowTheRoot(Application $app): void
    {
        self::configure($app, ['web-push.service_worker.path' => '/build/web-push-sw.js']);
    }
}
