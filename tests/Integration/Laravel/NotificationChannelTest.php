<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Notifications\AnonymousNotifiable;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures\OrderShipped;
use RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures\Subscriber;
use RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures\User;

final class NotificationChannelTest extends LaravelTestCase
{
    #[Test]
    public function it_should_send_a_notification_to_every_device_of_a_web_push_subscriber(): void
    {
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:7'), TestBrowser::chrome('a'));
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:7'), TestBrowser::firefox('b'));

        Subscriber::withId(7)->notifyNow(new OrderShipped());

        self::assertCount(2, $this->recordingPushTransport->delivered);
    }

    #[Test]
    public function it_should_notify_a_plain_user_under_the_id_its_devices_were_registered_with(): void
    {
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId(User::class.':42'), TestBrowser::chrome());

        User::withId(42)->notifyNow(new OrderShipped());

        self::assertCount(1, $this->recordingPushTransport->delivered);
    }

    #[Test]
    public function it_should_send_to_an_on_demand_route(): void
    {
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:9'), TestBrowser::chrome());

        (new AnonymousNotifiable())->route('web-push', 'user:9')->notifyNow(new OrderShipped());

        self::assertCount(1, $this->recordingPushTransport->delivered);
    }

    #[Test]
    public function it_should_send_nothing_to_another_subscriber_devices(): void
    {
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:8'), TestBrowser::chrome());

        Subscriber::withId(7)->notifyNow(new OrderShipped());

        self::assertSame([], $this->recordingPushTransport->delivered);
    }
}
