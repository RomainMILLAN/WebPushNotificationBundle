<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Application\DeliverPayload;
use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Application\Queue\QueuedDelivery;
use RomainMillan\WebPushNotification\Bridge\Laravel\Jobs\SendWebPushJob;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Testing\TestBrowser;

#[DefineEnvironment('dispatchThroughTheQueue')]
final class QueueDispatchTest extends LaravelTestCase
{
    #[Test]
    public function it_should_push_one_job_per_subscription_of_the_audience(): void
    {
        Queue::fake();
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), TestBrowser::chrome('a'));
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), TestBrowser::firefox('b'));
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:7'), TestBrowser::chrome('c'));

        $this->app()->make(PushDispatcher::class)->dispatch(SubscriberAudience::fromSubscriberId('user:42'), WebPushMessage::createWithTitle('Hello'), DeliveryOptions::createDefault());

        Queue::assertPushed(SendWebPushJob::class, 2);
    }

    #[Test]
    public function it_should_carry_no_endpoint_in_the_queued_job(): void
    {
        Queue::fake();
        $browser = TestBrowser::chrome('capability-token');
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);

        $this->app()->make(PushDispatcher::class)->dispatch(SubscriberAudience::fromSubscriberId('user:42'), WebPushMessage::createWithTitle('Hello'), DeliveryOptions::createDefault());

        Queue::assertPushed(SendWebPushJob::class, static fn (SendWebPushJob $job): bool => !str_contains(serialize($job), 'capability-token') && !str_contains(serialize($job), $browser->auth()));
    }

    #[Test]
    public function it_should_deliver_a_job_whose_subscription_still_belongs_to_the_expected_owner(): void
    {
        $browser = TestBrowser::chrome();
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);

        $this->queuedJobFor($browser, 'user:42')->handle($this->app()->make(DeliverPayload::class));

        self::assertCount(1, $this->recordingPushTransport->delivered);
    }

    #[Test]
    public function it_should_drop_a_job_whose_subscription_changed_owner(): void
    {
        $browser = TestBrowser::chrome();
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);
        $job = $this->queuedJobFor($browser, 'user:42');
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:7'), $browser);

        $job->handle($this->app()->make(DeliverPayload::class));

        self::assertSame([], $this->recordingPushTransport->delivered);
    }

    #[Test]
    public function it_should_survive_the_serialization_of_the_queue(): void
    {
        $browser = TestBrowser::chrome();
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);
        $job = $this->queuedJobFor($browser, 'user:42');

        $restored = unserialize(serialize($job));

        self::assertInstanceOf(SendWebPushJob::class, $restored);
        self::assertSame($job->delivery, $restored->delivery);
    }

    #[Test]
    public function it_should_not_ask_for_a_retry_after_a_permanent_failure(): void
    {
        $browser = TestBrowser::chrome();
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);
        $job = $this->queuedJobFor($browser, 'user:42');
        $this->recordingPushTransport->answer($job->delivery['subscription_id'], DeliveryOutcome::permanent(FailureCategory::Payload, 400));

        $job->handle($this->app()->make(DeliverPayload::class));

        self::assertCount(1, $this->recordingPushTransport->delivered);
    }

    protected function dispatchThroughTheQueue(Application $app): void
    {
        self::configure($app, ['web-push.delivery.dispatcher' => 'queue']);
    }

    private function queuedJobFor(TestBrowser $browser, string $expectedSubscriberId): SendWebPushJob
    {
        $subscription = $this->repository()->getByFingerprint($browser->address()->fingerprint());
        $delivery = QueuedDelivery::createForSubscription($subscription, (new PayloadEncoder())->encode(WebPushMessage::createWithTitle('Hello')), DeliveryOptions::createDefault())->toArray();

        return new SendWebPushJob(['expected_subscriber_id' => $expectedSubscriberId] + $delivery);
    }
}
