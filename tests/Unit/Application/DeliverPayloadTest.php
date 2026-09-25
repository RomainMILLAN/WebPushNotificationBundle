<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Application\Port\DeliveryOutcomeListener;
use RomainMillan\WebPushNotification\Application\RetirementPolicy;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryStatus;
use RomainMillan\WebPushNotification\Domain\Delivery\Everyone;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriptionsAudience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionExpired;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Support\TestApplication;

final class DeliverPayloadTest extends TestCase
{
    #[Test]
    public function it_should_a_subscriber_receives_on_every_active_device(): void
    {
        $app = new TestApplication();
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $app->registerSubscription()->register($alice, TestBrowser::chrome()->address());
        $app->registerSubscription()->register($alice, TestBrowser::firefox()->address());
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:2'), TestBrowser::safari()->address());

        $report = $app->sender()->send(SubscriberAudience::fromSubscriberId('user:1'), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault());

        self::assertSame(2, $report->countWith(DeliveryStatus::Delivered));
        self::assertCount(2, $app->transport->delivered);
    }

    #[Test]
    public function it_should_an_expired_subscription_is_retired_without_any_configuration(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address());
        $app->transport->answer($this->idOf(1), DeliveryOutcome::expired(410));

        $app->sender()->send(SubscriberAudience::fromSubscriberId('user:1'), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault());

        self::assertFalse($app->subscriptions->getByFingerprint($browser->address()->fingerprint())->isActive());
        self::assertContains(SubscriptionExpired::class, $app->events->dispatchedClasses());
    }

    #[Test]
    public function it_should_expiry_with_the_delete_policy_removes_the_row(): void
    {
        $app = new TestApplication(retirementPolicy: RetirementPolicy::Delete);
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());
        $app->transport->answer($this->idOf(1), DeliveryOutcome::expired(404));

        $app->sender()->send(SubscriberAudience::fromSubscriberId('user:1'), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault());

        self::assertSame(0, $app->subscriptions->countRows());
    }

    #[Test]
    public function it_should_a_vapid_rejection_never_expires_the_subscription(): void
    {
        $app = new TestApplication();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());
        $app->transport->answer($this->idOf(1), DeliveryOutcome::permanent(FailureCategory::Vapid, 403));

        $app->sender()->send(SubscriberAudience::fromSubscriberId('user:1'), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault());

        self::assertCount(1, $app->subscriptions->ownedBy(IdentifiedOwner::fromSubscriberId('user:1')));
    }

    #[Test]
    public function it_should_a_message_dispatched_before_a_reassignment_is_dropped_not_delivered_to_the_new_owner(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:alice'), $browser->address());
        $queuedFor = new SubscriptionsAudience([SubscriptionId::fromString($this->idOf(1))], IdentifiedOwner::fromSubscriberId('user:alice'));

        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:bob'), $browser->address());
        $report = $app->deliverPayload()->deliver($queuedFor, (new PayloadEncoder())->encode(WebPushMessage::createWithTitle('For Alice')), DeliveryOptions::createDefault());

        self::assertSame([], $app->transport->delivered);
        self::assertSame(FailureCategory::OwnerChanged, $report->outcomeFor(SubscriptionId::fromString($this->idOf(1)))->category);
    }

    #[Test]
    public function it_should_a_host_removed_from_the_allowlist_stops_deliveries_but_stays_readable(): void
    {
        $withCustomHost = new TestApplication(extraHosts: ['push.example.org']);
        $withCustomHost->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), (new TestBrowser('https://eu.push.example.org/abc'))->address());

        $withoutCustomHost = new TestApplication();
        $withoutCustomHost->subscriptions->save($withCustomHost->subscriptions->get(SubscriptionId::fromString($this->idOf(1))));

        $report = $withoutCustomHost->sender()->send(SubscriberAudience::fromSubscriberId('user:1'), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault());

        self::assertSame([], $withoutCustomHost->transport->delivered);
        self::assertSame(1, $report->countWith(DeliveryStatus::Skipped));
    }

    #[Test]
    public function it_should_everyone_reaches_anonymous_subscribers_too(): void
    {
        $app = new TestApplication();
        $app->registerSubscription()->register(new AnonymousOwner(), TestBrowser::chrome('anon')->address());
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('known')->address());

        $app->sender()->send(new Everyone(), WebPushMessage::createWithTitle('Hi all'), DeliveryOptions::createDefault());

        self::assertCount(2, $app->transport->delivered);
    }

    #[Test]
    public function it_should_a_failing_application_listener_never_breaks_the_delivery(): void
    {
        $failing = new class implements DeliveryOutcomeListener {
            public function onDeliveryOutcome(SubscriptionId $subscriptionId, DeliveryOutcome $outcome): void
            {
                throw new \RuntimeException('metrics down');
            }
        };
        $app = new TestApplication(listeners: [$failing]);
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());

        $report = $app->sender()->send(SubscriberAudience::fromSubscriberId('user:1'), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault());

        self::assertSame(1, $report->countWith(DeliveryStatus::Delivered));
    }

    private function idOf(int $sequence): string
    {
        return str_pad(dechex($sequence), 32, '0', \STR_PAD_LEFT);
    }
}
