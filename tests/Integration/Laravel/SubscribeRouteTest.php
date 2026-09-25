<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures\Subscriber;
use RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures\User;

final class SubscribeRouteTest extends LaravelTestCase
{
    #[Test]
    public function it_should_refuse_a_visitor_before_reading_the_body_when_anonymous_subscriptions_are_disabled(): void
    {
        $response = $this->postRaw('text/plain', str_repeat('x', 5 * 1024));

        $response->assertForbidden();
    }

    #[Test]
    public function it_should_store_the_subscription_of_an_authenticated_user_under_its_namespaced_id(): void
    {
        $browser = TestBrowser::chrome();
        $this->actingAs(User::withId(42));

        $response = $this->postRaw('application/json', $this->encode($browser->toJson()));

        $response->assertNoContent();
        self::assertTrue($this->repository()->getByFingerprint($browser->address()->fingerprint())->isOwnedBy(IdentifiedOwner::fromSubscriberId(User::class.':42')));
    }

    #[Test]
    public function it_should_store_the_subscription_under_the_id_named_by_a_web_push_subscriber(): void
    {
        $browser = TestBrowser::chrome();
        $this->actingAs(Subscriber::withId(7));

        $this->postRaw('application/json', $this->encode($browser->toJson()));

        self::assertTrue($this->repository()->getByFingerprint($browser->address()->fingerprint())->isOwnedBy(IdentifiedOwner::fromSubscriberId('user:7')));
    }

    #[Test]
    public function it_should_answer_415_to_a_body_that_is_not_json(): void
    {
        $this->actingAs(User::withId(42));

        $response = $this->postRaw('text/plain', $this->encode(TestBrowser::chrome()->toJson()));

        $response->assertStatus(415);
    }

    #[Test]
    public function it_should_answer_413_to_a_body_larger_than_4_kib(): void
    {
        $this->actingAs(User::withId(42));

        $response = $this->postRaw('application/json', '{"endpoint":"'.str_repeat('a', 5 * 1024).'"}');

        $response->assertStatus(413);
    }

    #[Test]
    public function it_should_answer_a_mute_400_to_a_malformed_subscription(): void
    {
        $this->actingAs(User::withId(42));

        $response = $this->postRaw('application/json', '{"endpoint":"https://fcm.googleapis.com/fcm/send/x","extra":1}');

        $response->assertStatus(400);
        self::assertSame('', $response->baseResponse->getContent());
    }

    #[Test]
    public function it_should_answer_204_to_a_refused_registration(): void
    {
        $this->actingAs(User::withId(42));
        $browser = new TestBrowser('https://push.evil.example/steal');

        $response = $this->postRaw('application/json', $this->encode($browser->toJson()));

        $response->assertNoContent();
        self::assertFalse($this->repository()->hasFingerprint($browser->address()->fingerprint()));
    }

    #[Test]
    public function it_should_answer_419_without_csrf_token_in_the_web_group(): void
    {
        // CSRF is skipped in the "testing" environment; given back before the rollback.
        $this->app()->instance('env', 'production');
        $this->beforeApplicationDestroyed(function (): void {
            $this->app()->instance('env', 'testing');
        });
        $this->actingAs(User::withId(42));

        $response = $this->postRaw('application/json', $this->encode(TestBrowser::chrome()->toJson()));

        $response->assertStatus(419);
    }

    #[Test]
    #[DefineEnvironment('allowAnonymousSubscriptions')]
    public function it_should_store_an_anonymous_subscription_when_enabled(): void
    {
        $browser = TestBrowser::chrome();
        $this->app()->make(RateLimiter::class)->for('web-push-anonymous', static fn (): Limit => Limit::perMinute(10));

        $response = $this->postRaw('application/json', $this->encode($browser->toJson()));

        $response->assertNoContent();
        self::assertTrue($this->repository()->getByFingerprint($browser->address()->fingerprint())->isOwnedBy(new AnonymousOwner()));
    }

    #[Test]
    #[DefineEnvironment('allowAnonymousSubscriptions')]
    public function it_should_rate_limit_anonymous_visitors_by_client_network(): void
    {
        $this->app()->make(RateLimiter::class)->for('web-push-anonymous', static fn (): Limit => Limit::perMinute(1));
        $this->postRaw('application/json', $this->encode(TestBrowser::chrome('first')->toJson()));

        $response = $this->postRaw('application/json', $this->encode(TestBrowser::chrome('second')->toJson()));

        $response->assertStatus(429);
    }

    protected function allowAnonymousSubscriptions(Application $app): void
    {
        self::configure($app, ['web-push.anonymous.enabled' => true, 'web-push.anonymous.rate_limiter' => 'web-push-anonymous']);
    }

    /**
     * @param array<mixed> $data
     */
    private function encode(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return TestResponse<Response>
     */
    private function postRaw(string $contentType, string $body): TestResponse
    {
        return $this->call('POST', '/web-push/subscriptions', [], [], [], ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'], $body);
    }
}
