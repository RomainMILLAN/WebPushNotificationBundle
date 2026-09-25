<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Message\ActionUrl;
use RomainMillan\WebPushNotification\Domain\Message\Origin;

use function Symfony\Component\String\u;

final class ActionUrlSignerTest extends LaravelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The generator builds on the current request: here, one made to APP_URL.
        $urlGenerator = $this->app()->make(UrlGenerator::class);
        \assert($urlGenerator instanceof \Illuminate\Routing\UrlGenerator);
        $urlGenerator->forceScheme('https');
        $urlGenerator->forceRootUrl('https://app.example');
    }

    #[Test]
    public function it_should_produce_an_absolute_url_within_the_application_origin(): void
    {
        $actionUrl = $this->signAcknowledgement();

        self::assertTrue($this->app()->make(Origin::class)->isOriginOf($actionUrl->toString()));
    }

    #[Test]
    public function it_should_let_the_signed_middleware_accept_a_signed_url(): void
    {
        $response = $this->post($this->signAcknowledgement()->toString());

        $response->assertOk();
        $response->assertSee('acknowledged 42');
    }

    #[Test]
    public function it_should_let_the_signed_middleware_reject_a_tampered_url(): void
    {
        $tampered = u($this->signAcknowledgement()->toString())->replace('/orders/42/', '/orders/43/')->toString();

        $response = $this->post($tampered);

        $response->assertForbidden();
    }

    #[Test]
    public function it_should_let_the_signed_middleware_reject_an_expired_url(): void
    {
        $actionUrl = $this->signAcknowledgement();
        $this->travel(2)->minutes();

        $response = $this->post($actionUrl->toString());

        $response->assertForbidden();
    }

    #[Test]
    public function it_should_refuse_a_negative_validity(): void
    {
        $validity = new \DateInterval('PT1M');
        $validity->invert = 1;

        $this->expectException(InvalidValue::class);

        $this->app()->make(ActionUrlSigner::class)->sign('orders.acknowledge', ['order' => 42], $validity);
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->post('/orders/{order}/acknowledge', static fn (string $order): string => 'acknowledged '.$order)->middleware('signed')->name('orders.acknowledge');
    }

    private function signAcknowledgement(): ActionUrl
    {
        return $this->app()->make(ActionUrlSigner::class)->sign('orders.acknowledge', ['order' => 42], new \DateInterval('PT1M'));
    }
}
