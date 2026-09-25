<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\Proof\OwnerProof;
use RomainMillan\WebPushNotification\Application\Proof\PossessionProof;
use RomainMillan\WebPushNotification\Application\RemoveAllSubscriptions;
use RomainMillan\WebPushNotification\Application\RevokeSubscription;
use RomainMillan\WebPushNotification\Application\Unsubscribe;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionUnsubscribed;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use RomainMillan\WebPushNotification\Domain\Subscription\UnsubscribeCause;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Support\TestApplication;

final class UnsubscribeTest extends TestCase
{
    #[Test]
    public function it_should_the_device_proves_possession_whatever_its_owner_logout_flow(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address());

        $this->unsubscribe($app)->unsubscribe(PushEndpoint::fromString($browser->endpoint()), new PossessionProof($browser->auth()));

        self::assertSame(0, $app->subscriptions->countRows());
        $event = $app->events->dispatched[\count($app->events->dispatched) - 1];
        self::assertInstanceOf(SubscriptionUnsubscribed::class, $event);
        self::assertSame(UnsubscribeCause::Possession, $event->cause);
    }

    #[Test]
    public function it_should_a_wrong_auth_changes_nothing_and_says_nothing(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address());

        $this->unsubscribe($app)->unsubscribe(PushEndpoint::fromString($browser->endpoint()), new PossessionProof(str_repeat('A', 22)));
        $this->unsubscribe($app)->unsubscribe(PushEndpoint::fromString('https://fcm.googleapis.com/fcm/send/unknown'), new PossessionProof($browser->auth()));

        self::assertSame(1, $app->subscriptions->countRows());
    }

    #[Test]
    public function it_should_an_owner_only_removes_their_own_device(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address());

        $this->unsubscribe($app)->unsubscribe(PushEndpoint::fromString($browser->endpoint()), new OwnerProof(IdentifiedOwner::fromSubscriberId('user:2')));
        $this->unsubscribe($app)->unsubscribe(PushEndpoint::fromString($browser->endpoint()), new OwnerProof(new AnonymousOwner()));
        self::assertSame(1, $app->subscriptions->countRows());

        $this->unsubscribe($app)->unsubscribe(PushEndpoint::fromString($browser->endpoint()), new OwnerProof(IdentifiedOwner::fromSubscriberId('user:1')));
        self::assertSame(0, $app->subscriptions->countRows());
    }

    #[Test]
    public function it_should_revoke_filters_on_the_owner(): void
    {
        $app = new TestApplication();
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());
        $id = $app->subscriptions->ownedBy(IdentifiedOwner::fromSubscriberId('user:1'))->leastRecentlyRegistered(1)[0]->id();
        $revoke = new RevokeSubscription($app->subscriptionTransaction, $app->clock);

        $revoke->revoke(IdentifiedOwner::fromSubscriberId('user:2'), $id);
        self::assertSame(1, $app->subscriptions->countRows());

        $revoke->revoke(IdentifiedOwner::fromSubscriberId('user:1'), $id);
        self::assertSame(0, $app->subscriptions->countRows());
    }

    #[Test]
    public function it_should_account_removal_deletes_every_device_active_or_retired(): void
    {
        $app = new TestApplication(maxPerSubscriber: 1);
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $app->registerSubscription()->register($alice, TestBrowser::chrome('a')->address());
        $app->clock->advance('+1 minute');
        $app->registerSubscription()->register($alice, TestBrowser::chrome('b')->address());
        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:2'), TestBrowser::chrome('c')->address());

        (new RemoveAllSubscriptions($app->subscriptionTransaction, $app->subscriptions, $app->clock))->removeAllOf(SubscriberId::fromString('user:1'));

        self::assertSame(1, $app->subscriptions->countRows());
    }

    private function unsubscribe(TestApplication $app): Unsubscribe
    {
        return new Unsubscribe($app->subscriptionTransaction, $app->clock);
    }
}
