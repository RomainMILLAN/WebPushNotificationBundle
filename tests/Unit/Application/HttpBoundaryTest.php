<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\AnonymousGate;
use RomainMillan\WebPushNotification\Application\ClientConfiguration;
use RomainMillan\WebPushNotification\Application\Exception\AnonymousSubscriptionsDisabled;
use RomainMillan\WebPushNotification\Application\Exception\InvalidRequest;
use RomainMillan\WebPushNotification\Application\Exception\RequestTooLarge;
use RomainMillan\WebPushNotification\Application\Exception\UnsupportedMediaType;
use RomainMillan\WebPushNotification\Application\Http\JsonRequestBody;
use RomainMillan\WebPushNotification\Application\Http\SubscribeRequest;
use RomainMillan\WebPushNotification\Application\Port\CurrentOwner;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerScript;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Infrastructure\ClientState\HmacClientStateMarkerFactory;
use RomainMillan\WebPushNotification\Testing\TestBrowser;

final class HttpBoundaryTest extends TestCase
{
    #[Test]
    public function it_should_deny_anonymous_visitors_by_default(): void
    {
        $this->expectException(AnonymousSubscriptionsDisabled::class);

        (new AnonymousGate($this->currentOwner(new AnonymousOwner()), false))->resolve();
    }

    #[Test]
    public function it_should_let_anonymous_visitors_through_when_opted_in_and_subscribers_always(): void
    {
        self::assertInstanceOf(AnonymousOwner::class, (new AnonymousGate($this->currentOwner(new AnonymousOwner()), true))->resolve());
        self::assertInstanceOf(IdentifiedOwner::class, (new AnonymousGate($this->currentOwner(IdentifiedOwner::fromSubscriberId('user:1')), false))->resolve());
    }

    #[Test]
    public function it_should_parse_a_browser_subscription(): void
    {
        $browser = TestBrowser::chrome();

        $request = SubscribeRequest::fromBody(JsonRequestBody::fromStream('application/json; charset=utf-8', $this->stream(json_encode($browser->toJson() + ['contentEncoding' => 'aes128gcm'], \JSON_THROW_ON_ERROR))));

        self::assertTrue($request->address()->sameEndpointAs($browser->address()));
    }

    #[Test]
    public function it_should_read_only_json(): void
    {
        $this->expectException(UnsupportedMediaType::class);

        JsonRequestBody::fromStream('text/plain', $this->stream('{}'));
    }

    #[Test]
    public function it_should_bound_the_body_actually_read_even_without_content_length(): void
    {
        $this->expectException(RequestTooLarge::class);

        JsonRequestBody::fromStream('application/json', $this->stream('{"endpoint":"'.str_repeat('a', 5000).'"}'));
    }

    #[Test]
    public function it_should_bound_the_json_depth(): void
    {
        $this->expectException(InvalidRequest::class);

        JsonRequestBody::fromStream('application/json', $this->stream('{"a":{"b":{"c":{"d":{"e":1}}}}}'));
    }

    #[Test]
    public function it_should_refuse_unknown_fields(): void
    {
        $this->expectException(InvalidRequest::class);

        SubscribeRequest::fromBody(JsonRequestBody::fromStream('application/json', $this->stream(json_encode(TestBrowser::chrome()->toJson() + ['owner' => 'user:1'], \JSON_THROW_ON_ERROR))));
    }

    #[Test]
    public function it_should_keep_the_injected_configuration_inside_its_script(): void
    {
        $encoded = ServiceWorkerScript::encodeForScript(['fallbackTitle' => "</script><script>alert(1)</script>\u{2028}'\""]);

        self::assertStringNotContainsString('</script>', $encoded);
        self::assertStringNotContainsString("\u{2028}", $encoded);
        self::assertStringNotContainsString("'", $encoded);
    }

    #[Test]
    public function it_should_render_an_attribute_safe_meta_tag_without_marker_for_anonymous(): void
    {
        $configuration = new ClientConfiguration(
            ['publicKey' => 'key', 'serviceWorker' => '/web-push-sw.js', 'subscribe' => '/web-push/subscribe', 'unsubscribe' => '/web-push/unsubscribe', 'clickPrefixes' => ['/'], 'stateCache' => 'web-push-state'],
            new HmacClientStateMarkerFactory('secret'),
        );

        $meta = $configuration->renderMetaTag(new AnonymousOwner(), 'X-CSRF-Token', '"><script>');

        self::assertStringNotContainsString('"><script>', $meta);
        self::assertStringContainsString('&quot;clientState&quot;:&quot;&quot;', $meta);
        self::assertMatchesRegularExpression('/clientState&quot;:&quot;[0-9a-f]{16}&quot;/', $configuration->renderMetaTag(IdentifiedOwner::fromSubscriberId('user:1'), 'X-CSRF-Token', 't'));
    }

    private function currentOwner(Owner $owner): CurrentOwner
    {
        return new class($owner) implements CurrentOwner {
            public function __construct(private readonly Owner $owner)
            {
            }

            public function resolve(): Owner
            {
                return $this->owner;
            }
        };
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
