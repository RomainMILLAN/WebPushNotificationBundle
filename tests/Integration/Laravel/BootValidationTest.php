<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Contracts\Config\Repository;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\InvalidConfiguration;
use RomainMillan\WebPushNotification\Bridge\Laravel\Configuration\WebPushConfiguration;
use RomainMillan\WebPushNotification\Bridge\Laravel\WebPushNotificationServiceProvider;

/** Each test changes the configuration, then boots the provider again. */
final class BootValidationTest extends LaravelTestCase
{
    #[Test]
    public function it_should_fail_to_boot_when_anonymous_subscriptions_have_no_rate_limiter(): void
    {
        $this->changeConfiguration(['web-push.anonymous.enabled' => true]);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Cannot enable anonymous web push subscriptions without web-push.anonymous.rate_limiter');

        $this->bootAgain();
    }

    #[Test]
    public function it_should_fail_to_boot_with_a_malformed_vapid_public_key(): void
    {
        $this->changeConfiguration(['web-push.vapid.public_key' => 'not-a-p256-point']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('VAPID public key');

        $this->bootAgain();
    }

    #[Test]
    public function it_should_fail_to_boot_with_an_encryption_key_that_is_not_32_bytes(): void
    {
        $this->changeConfiguration(['web-push.encryption.current' => 'k1:'.base64_encode('short')]);

        $this->expectException(InvalidConfiguration::class);

        $this->bootAgain();
    }

    #[Test]
    public function it_should_fail_to_boot_with_an_extra_push_host_that_is_an_ip(): void
    {
        $this->changeConfiguration(['web-push.push_services.extra_hosts' => ['10.0.0.1']]);

        $this->expectException(InvalidConfiguration::class);

        $this->bootAgain();
    }

    #[Test]
    public function it_should_fail_to_boot_without_vapid_subject_when_the_app_url_is_not_https(): void
    {
        $this->changeConfiguration(['app.url' => 'http://myapp.test', 'web-push.vapid.subject' => '']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Cannot derive a VAPID subject from a non-https APP_URL');

        $this->bootAgain();
    }

    #[Test]
    public function it_should_derive_the_vapid_subject_from_an_https_app_url(): void
    {
        $this->changeConfiguration(['app.url' => 'https://app.example/', 'web-push.vapid.subject' => '']);

        $this->bootAgain();

        self::assertSame('https://app.example', $this->app()->make(WebPushConfiguration::class)->vapidCredentials()->toWebPushAuth()['VAPID']['subject']);
    }

    #[Test]
    public function it_should_fail_to_boot_with_a_register_url_of_another_origin(): void
    {
        $this->changeConfiguration(['web-push.service_worker.register_url' => '//cdn.example/sw.js']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('web-push.service_worker.register_url');

        $this->bootAgain();
    }

    #[Test]
    public function it_should_fail_to_boot_with_a_subscriber_resolver_that_does_not_resolve(): void
    {
        $this->changeConfiguration(['web-push.subscriber.resolver' => \stdClass::class]);

        $this->expectException(InvalidConfiguration::class);

        $this->bootAgain();
    }

    #[Test]
    public function it_should_boot_in_the_console_before_any_vapid_key_is_set(): void
    {
        $this->changeConfiguration(['web-push.vapid.public_key' => '', 'web-push.vapid.private_key' => '', 'app.url' => 'http://app.example']);

        $this->bootAgain();

        self::assertFalse($this->app()->make(WebPushConfiguration::class)->isVapidConfigured());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function changeConfiguration(array $values): void
    {
        $this->app()->make(Repository::class)->set($values);
        $this->app()->forgetInstance(WebPushConfiguration::class);
    }

    private function bootAgain(): void
    {
        $this->app()->register(WebPushNotificationServiceProvider::class, true);
    }
}
