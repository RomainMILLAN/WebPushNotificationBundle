<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Application\Exception\AnonymousSubscriptionsDisabled;
use RomainMillan\WebPushNotification\Application\Exception\InvalidRequest;
use RomainMillan\WebPushNotification\Application\Exception\LockNotAcquired;
use RomainMillan\WebPushNotification\Application\Exception\RequestTooLarge;
use RomainMillan\WebPushNotification\Application\Exception\UnsupportedMediaType;
use RomainMillan\WebPushNotification\Application\Http\ForbiddenRequest;
use RomainMillan\WebPushNotification\Application\Http\HttpStatus;
use RomainMillan\WebPushNotification\Application\Http\JsonRequestBody;
use RomainMillan\WebPushNotification\Application\Http\RateLimitKey;
use RomainMillan\WebPushNotification\Application\Http\TooManyRequests;
use RomainMillan\WebPushNotification\Application\Http\UnsubscribeRequest;
use RomainMillan\WebPushNotification\Application\PurgeSubscriptions;
use RomainMillan\WebPushNotification\Application\PurgeWindows;
use RomainMillan\WebPushNotification\Application\Queue\QueuedDelivery;
use RomainMillan\WebPushNotification\Application\Queue\QueuedDeliveryPlanner;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerConfig;
use RomainMillan\WebPushNotification\Application\ServiceWorker\ServiceWorkerScript;
use RomainMillan\WebPushNotification\Domain\Delivery\Everyone;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionsPurged;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Support\TestApplication;

final class SharedBridgeHelpersTest extends TestCase
{
    /**
     * @return array<string, array{WebPushNotificationException, int}>
     */
    public static function failures(): array
    {
        return [
            'anonymous disabled' => [AnonymousSubscriptionsDisabled::create(), 403],
            'csrf' => [ForbiddenRequest::invalidCsrfToken(), 403],
            'media type' => [UnsupportedMediaType::create(), 415],
            'too large' => [RequestTooLarge::create(4096), 413],
            'rate limited' => [TooManyRequests::create(), 429],
            'lock' => [LockNotAcquired::create(), 503],
            'invalid' => [InvalidRequest::because('x'), 400],
            'invalid value' => [InvalidValue::because('x'), 400],
        ];
    }

    #[DataProvider('failures')]
    #[Test]
    public function it_should_map_every_failure_to_one_status(WebPushNotificationException $failure, int $status): void
    {
        self::assertSame($status, HttpStatus::forFailure($failure));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function clientAddresses(): array
    {
        return [
            'IPv4 as is' => ['203.0.113.7', 'web-push:203.0.113.7'],
            'IPv6 aggregated per /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', 'web-push:2001:db8:1:2::/64'],
            'same /64, same bucket' => ['2001:db8:1:2::1', 'web-push:2001:db8:1:2::/64'],
            'garbage' => ['not an ip', 'web-push:unknown'],
            'empty' => ['', 'web-push:unknown'],
        ];
    }

    #[DataProvider('clientAddresses')]
    #[Test]
    public function it_should_key_rate_limits_by_client_network(string $ip, string $key): void
    {
        self::assertSame($key, RateLimitKey::fromClientIp($ip)->toString());
    }

    #[Test]
    public function it_should_round_trip_a_queued_delivery_through_scalars(): void
    {
        $app = new TestApplication();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());
        $options = DeliveryOptions::createDefault()->withTtl(60)->withUrgency(Urgency::High)->withTopic('t1');
        $planner = new QueuedDeliveryPlanner($app->subscriptions, new PayloadEncoder());

        $deliveries = iterator_to_array($planner->plan(SubscriberAudience::fromSubscriberId('user:1'), WebPushMessage::createWithTitle('Hi'), $options), false);

        self::assertCount(1, $deliveries);
        $queued = json_decode(json_encode($deliveries[0]->toArray(), \JSON_THROW_ON_ERROR), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($queued);
        $restored = QueuedDelivery::fromArray($queued);
        self::assertEquals($options, $restored->options());
        self::assertSame($deliveries[0]->payload()->toString(), $restored->payload()->toString());
        self::assertSame('user:1', $restored->toArray()['expected_subscriber_id']);

        $subscription = $app->subscriptions->ownedBy(IdentifiedOwner::fromSubscriberId('user:1'))->leastRecentlyRegistered(1)[0];
        self::assertSame(FailureCategory::None, $restored->audience()->admits($subscription));
    }

    #[Test]
    public function it_should_queue_anonymous_deliveries_with_an_anonymous_expected_owner(): void
    {
        $app = new TestApplication();
        $app->registerSubscription()->register(new AnonymousOwner(), TestBrowser::chrome('anon')->address());
        $planner = new QueuedDeliveryPlanner($app->subscriptions, new PayloadEncoder());

        $deliveries = iterator_to_array($planner->plan(new Everyone(), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault()), false);

        self::assertCount(1, $deliveries);
        self::assertSame('', $deliveries[0]->toArray()['expected_subscriber_id']);
        $subscription = $app->subscriptions->getByFingerprint(TestBrowser::chrome('anon')->address()->fingerprint());
        self::assertSame(FailureCategory::None, $deliveries[0]->audience()->admits($subscription));
    }

    #[Test]
    public function it_should_skip_retired_devices_when_planning(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address());
        $subscription = $app->subscriptions->getByFingerprint($browser->address()->fingerprint());
        $subscription->expire(new \DateTimeImmutable());
        $app->subscriptions->save($subscription);

        $planned = (new QueuedDeliveryPlanner($app->subscriptions, new PayloadEncoder()))->plan(new Everyone(), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault());

        self::assertCount(0, iterator_to_array($planned, false));
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function malformedQueuedDeliveries(): array
    {
        $valid = ['subscription_id' => str_repeat('a', 32), 'expected_subscriber_id' => '', 'payload' => '{}', 'options' => ['ttl' => 1, 'urgency' => 'normal', 'topic' => '']];

        return [
            'no id' => [['subscription_id' => 1] + $valid],
            'owner not a string' => [['expected_subscriber_id' => null] + $valid],
            'payload not a string' => [['payload' => []] + $valid],
            'options missing' => [['options' => null] + $valid],
            'ttl not an int' => [['options' => ['ttl' => '1', 'urgency' => 'normal', 'topic' => '']] + $valid],
            'urgency missing' => [['options' => ['ttl' => 1, 'topic' => '']] + $valid],
            'topic missing' => [['options' => ['ttl' => 1, 'urgency' => 'normal']] + $valid],
        ];
    }

    /**
     * @param array<mixed> $queued
     */
    #[DataProvider('malformedQueuedDeliveries')]
    #[Test]
    public function it_should_refuse_a_malformed_queued_delivery(array $queued): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QueuedDelivery::fromArray($queued);
    }

    #[Test]
    public function it_should_expose_the_worker_settings_under_their_contract_names(): void
    {
        $config = ServiceWorkerConfig::fromSettings('My app', '/icon.png', '/badge.png', ['/app/'], ['cdn.example.com'], 'my-state');

        self::assertSame(
            ['fallbackTitle' => 'My app', 'icon' => '/icon.png', 'badge' => '/badge.png', 'clickPrefixes' => ['/app/'], 'assetHosts' => ['cdn.example.com'], 'stateCache' => 'my-state'],
            $config->toArray(),
        );
        self::assertSame([], ServiceWorkerConfig::fromSettings('App', '', '', [], [], 'x')->toArray()['clickPrefixes']);
    }

    /**
     * @return array<string, array{string, string, string, list<string>, list<string>, string}>
     */
    public static function invalidWorkerSettings(): array
    {
        return [
            'blank title' => ['  ', '', '', ['/'], [], 'state'],
            'title too long' => [str_repeat('t', 121), '', '', ['/'], [], 'state'],
            'icon off origin over http' => ['App', 'http://tracker.example/i.png', '', ['/'], [], 'state'],
            'badge not an URL' => ['App', '', 'badge.png', ['/'], [], 'state'],
            'prefix not a path' => ['App', '', '', ['app/'], [], 'state'],
            'scheme-relative prefix' => ['App', '', '', ['//evil'], [], 'state'],
            'prefix with backslash' => ['App', '', '', ['/a\\b'], [], 'state'],
            'prefix with space' => ['App', '', '', ['/a b'], [], 'state'],
            'asset host with port' => ['App', '', '', ['/'], ['cdn.example.com:8443'], 'state'],
            'uppercase state cache' => ['App', '', '', ['/'], [], 'State'],
            'empty state cache' => ['App', '', '', ['/'], [], ''],
        ];
    }

    /**
     * @param list<string> $prefixes
     * @param list<string> $hosts
     */
    #[DataProvider('invalidWorkerSettings')]
    #[Test]
    public function it_should_validate_worker_settings(string $title, string $icon, string $badge, array $prefixes, array $hosts, string $stateCache): void
    {
        $this->expectException(InvalidValue::class);

        ServiceWorkerConfig::fromSettings($title, $icon, $badge, $prefixes, $hosts, $stateCache);
    }

    #[Test]
    public function it_should_prepend_the_escaped_configuration_to_the_prebuilt_worker(): void
    {
        $script = (new ServiceWorkerScript(__DIR__.'/../../Fixtures/web-push-sw.stub.js'))->render(ServiceWorkerConfig::fromSettings('</script>', '', '', ['/'], [], 'state'));

        self::assertStringStartsWith('self.__WEB_PUSH_CONFIG__ = {"fallbackTitle":"\\u003C/script\\u003E"', $script);
        self::assertStringNotContainsString('</script>', $script);
        self::assertStringContainsString("};\n/* Test stub", $script);
    }

    #[Test]
    public function it_should_ship_the_prebuilt_worker_with_the_package(): void
    {
        self::assertStringContainsString('self.__WEB_PUSH_CONFIG__ = ', ServiceWorkerScript::createFromPackageDist()->render(ServiceWorkerConfig::fromSettings('App', '', '', ['/'], [], 'state')));
    }

    #[Test]
    public function it_should_say_when_the_prebuilt_worker_is_missing(): void
    {
        $this->expectException(InvalidValue::class);

        (new ServiceWorkerScript('/nowhere/web-push-sw.js'))->render(ServiceWorkerConfig::fromSettings('App', '', '', ['/'], [], 'state'));
    }

    #[Test]
    public function it_should_compute_purge_cutoffs_from_durations(): void
    {
        $windows = PurgeWindows::fromDurations('30 days', '90 days');
        $now = new \DateTimeImmutable('2026-09-24 12:00:00');

        self::assertEquals(new \DateTimeImmutable('2026-08-25 12:00:00'), $windows->retiredCutoff($now));
        self::assertEquals(new \DateTimeImmutable('2026-06-26 12:00:00'), $windows->anonymousCutoff($now));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDurations(): array
    {
        return ['zero' => ['0 days'], 'negative' => ['-3 days'], 'garbage' => ['whenever']];
    }

    #[DataProvider('invalidDurations')]
    #[Test]
    public function it_should_refuse_a_non_positive_purge_duration(string $duration): void
    {
        $this->expectException(InvalidValue::class);

        PurgeWindows::fromDurations($duration, '90 days');
    }

    #[Test]
    public function it_should_purge_retired_and_abandoned_anonymous_subscriptions_and_publish_the_counts(): void
    {
        $app = new TestApplication();
        $retired = TestBrowser::chrome('retired');
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), $retired->address());
        $subscription = $app->subscriptions->getByFingerprint($retired->address()->fingerprint());
        $subscription->expire($app->clock->now());
        $app->subscriptions->save($subscription);
        $app->registerSubscription()->register(new AnonymousOwner(), TestBrowser::chrome('anon')->address());
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:2'), TestBrowser::chrome('kept')->address());
        $app->clock->advance('+100 days');

        $purged = (new PurgeSubscriptions($app->subscriptions, PurgeWindows::fromDurations('30 days', '90 days'), $app->events, $app->clock))->purge();

        self::assertSame([1, 1], [$purged->retired, $purged->abandonedAnonymous]);
        self::assertSame(1, $app->subscriptions->countRows());
        self::assertInstanceOf(SubscriptionsPurged::class, $app->events->dispatched[\count($app->events->dispatched) - 1]);
    }

    #[Test]
    public function it_should_parse_an_unsubscription_and_its_proof(): void
    {
        $browser = TestBrowser::chrome();
        $request = UnsubscribeRequest::fromBody($this->body(['endpoint' => $browser->endpoint(), 'keys' => ['auth' => $browser->auth(), 'p256dh' => 'ignored']]));

        self::assertSame($browser->endpoint(), $request->endpoint()->toString());
        self::assertSame('[redacted]', $request->proof()->__debugInfo()['auth']);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidUnsubscriptions(): array
    {
        return [
            'no endpoint' => [['keys' => ['auth' => 'x']]],
            'no keys' => [['endpoint' => 'https://fcm.googleapis.com/fcm/send/x']],
            'auth not a string' => [['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'keys' => ['auth' => 1]]],
            'endpoint not canonical' => [['endpoint' => 'https://FCM.googleapis.com/x', 'keys' => ['auth' => 'x']]],
        ];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidUnsubscriptions')]
    #[Test]
    public function it_should_refuse_a_malformed_unsubscription(array $data): void
    {
        $this->expectException(InvalidRequest::class);

        UnsubscribeRequest::fromBody($this->body($data));
    }

    /**
     * @param array<mixed> $data
     */
    private function body(array $data): JsonRequestBody
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, json_encode($data, \JSON_THROW_ON_ERROR));
        rewind($stream);

        return JsonRequestBody::fromStream('application/json', $stream);
    }
}
