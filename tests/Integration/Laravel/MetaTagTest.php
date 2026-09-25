<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures\User;

final class MetaTagTest extends LaravelTestCase
{
    #[Test]
    public function it_should_render_the_client_configuration_with_the_directive(): void
    {
        $response = $this->get('/directive');

        $response->assertOk();
        self::assertStringContainsString('<meta name="web-push-config" content="', (string) $response->baseResponse->getContent());
        self::assertStringContainsString('&quot;subscribe&quot;:&quot;/web-push/subscriptions&quot;', (string) $response->baseResponse->getContent());
    }

    #[Test]
    public function it_should_register_the_package_worker_by_default(): void
    {
        $response = $this->get('/directive');

        self::assertStringContainsString('&quot;serviceWorker&quot;:&quot;/web-push-sw.js&quot;', (string) $response->baseResponse->getContent());
    }

    #[Test]
    #[DefineEnvironment('registerTheApplicationWorker')]
    public function it_should_register_the_application_worker_when_configured(): void
    {
        $response = $this->get('/directive');

        self::assertStringContainsString('&quot;serviceWorker&quot;:&quot;/sw.js&quot;', (string) $response->baseResponse->getContent());
    }

    #[Test]
    public function it_should_render_the_client_configuration_with_the_component(): void
    {
        $this->actingAs(User::withId(42));

        $response = $this->get('/component');

        self::assertStringContainsString('<meta name="web-push-config" content="', (string) $response->baseResponse->getContent());
        self::assertMatchesRegularExpression('/&quot;clientState&quot;:&quot;[0-9a-f]{16}&quot;/', (string) $response->baseResponse->getContent());
    }

    #[Test]
    public function it_should_mark_a_page_carrying_the_tag_private_whatever_the_application_set(): void
    {
        $response = $this->get('/component');

        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function it_should_leave_the_cache_headers_of_a_page_without_the_tag(): void
    {
        $response = $this->get('/without-tag');

        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    protected function registerTheApplicationWorker(Application $app): void
    {
        self::configure($app, ['web-push.service_worker.register_url' => '/sw.js']);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->group(['middleware' => 'web'], static function (Router $router): void {
            $router->get('/directive', static fn (): string => Blade::render('@webPushMeta'));
            $router->get('/component', static fn (): Response => (new Response(Blade::render('<x-web-push::meta/>')))->setPublic()->setMaxAge(600)->setSharedMaxAge(600));
            $router->get('/without-tag', static fn (): Response => (new Response('<p>static</p>'))->setPublic()->setMaxAge(600));
        });
    }
}
