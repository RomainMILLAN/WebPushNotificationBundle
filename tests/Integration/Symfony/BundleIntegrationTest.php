<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Application\RegisterSubscription;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\DoctrineTransactionBoundary;
use RomainMillan\WebPushNotification\Bridge\Symfony\Messenger\SendWebPush;
use RomainMillan\WebPushNotification\Bridge\Symfony\Messenger\SendWebPushHandler;
use RomainMillan\WebPushNotification\Bridge\Symfony\Signing\SymfonyActionUrlSigner;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionRegistered;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Integration\Symfony\App\TestKernel;
use RomainMillan\WebPushNotification\Tests\Integration\Symfony\App\TestUser;
use RomainMillan\WebPushNotification\Tests\Integration\Symfony\App\UrgentNotification;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;

use function Symfony\Component\String\u;

final class BundleIntegrationTest extends SymfonyTestCase
{
    #[Test]
    public function it_should_publish_events_only_after_the_outermost_commit(): void
    {
        $this->browser();
        $published = new \ArrayObject();
        $this->service(EventDispatcherInterface::class)->addListener(SubscriptionRegistered::class, static function () use ($published): void {
            $published->append('registered');
        });
        $connection = $this->service(Connection::class);

        $connection->beginTransaction();
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());
        self::assertCount(0, $published, 'nothing may be published inside the application transaction');
        $connection->commit();

        self::assertCount(1, $published);
    }

    #[Test]
    public function it_should_publish_nothing_when_the_outer_transaction_rolls_back(): void
    {
        $this->browser();
        $published = new \ArrayObject();
        $this->service(EventDispatcherInterface::class)->addListener(SubscriptionRegistered::class, static function () use ($published): void {
            $published->append('registered');
        });
        $connection = $this->service(Connection::class);

        $connection->beginTransaction();
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());
        $connection->rollBack();

        self::assertCount(0, $published);
        self::assertCount(0, $this->service(SubscriptionRepository::class)->ownedBy(IdentifiedOwner::fromSubscriberId('user:1')));
    }

    #[Test]
    public function it_should_deliver_immediately_and_retire_an_expired_device(): void
    {
        $this->browser();
        $browser = TestBrowser::chrome();
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address());
        $id = $this->service(SubscriptionRepository::class)->getByFingerprint($browser->address()->fingerprint())->id();
        $this->transport()->recorder->answer($id->toString(), DeliveryOutcome::expired(410));

        $this->service(PushDispatcher::class)->dispatch(SubscriberAudience::fromSubscriberId('user:1'), WebPushMessage::createWithTitle('Hi'), DeliveryOptions::createDefault());

        self::assertCount(1, $this->transport()->recorder->delivered);
        self::assertFalse($this->service(SubscriptionRepository::class)->get($id)->isActive());
    }

    #[Test]
    public function it_should_queue_one_message_per_device_and_drop_it_if_the_device_changed_hands(): void
    {
        $this->browser('messenger');
        $browser = TestBrowser::chrome();
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:alice'), $browser->address());
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:alice'), TestBrowser::firefox()->address());

        $this->service(PushDispatcher::class)->dispatch(SubscriberAudience::fromSubscriberId('user:alice'), WebPushMessage::createWithTitle('For Alice'), DeliveryOptions::createDefault());

        $async = self::getContainer()->get('messenger.transport.async');
        \assert($async instanceof InMemoryTransport);
        $envelopes = $async->getSent();
        self::assertCount(2, $envelopes);

        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:bob'), $browser->address());
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            \assert($message instanceof SendWebPush);
            $this->service(SendWebPushHandler::class)($message);
        }

        self::assertCount(1, $this->transport()->recorder->delivered, 'Bob must never receive Alice\'s notification');
    }

    #[Test]
    public function it_should_send_through_the_notifier_web_push_channel(): void
    {
        $this->browser();
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:7'), TestBrowser::chrome()->address());

        $notifier = self::getContainer()->get('test.notifier');
        \assert($notifier instanceof NotifierInterface);
        $notifier->send((new Notification('Payment received', ['web_push']))->content('120 €'), new TestUser(7, 'bob@example.com'));

        self::assertCount(1, $this->transport()->recorder->delivered);
    }

    #[Test]
    public function it_should_print_a_vapid_key_pair_with_a_warning(): void
    {
        $this->browser();
        $tester = new CommandTester((new Application(self::bootedKernel()))->find('webpush:vapid:generate'));

        $tester->execute([]);

        self::assertMatchesRegularExpression('/VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}/', $tester->getDisplay());
        self::assertStringContainsString('never commit', $tester->getDisplay());
    }

    #[Test]
    public function it_should_send_a_test_notification_without_printing_any_endpoint(): void
    {
        $this->browser();
        $browser = TestBrowser::chrome('secret-token');
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address());
        $tester = new CommandTester((new Application(self::bootedKernel()))->find('webpush:test'));

        $tester->execute(['subscriber' => 'user:1']);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('secret-token', $tester->getDisplay());
    }

    #[Test]
    public function it_should_purge(): void
    {
        $this->browser();
        $tester = new CommandTester((new Application(self::bootedKernel()))->find('webpush:purge'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
    }

    #[Test]
    public function it_should_refuse_to_build_with_anonymous_subscriptions_and_no_rate_limiter(): void
    {
        TestKernel::$extraConfig = ['anonymous' => ['enabled' => true]];

        $this->expectExceptionMessage('require anonymous.rate_limiter');

        self::bootKernel();
    }

    #[Test]
    public function it_should_refuse_to_build_with_an_extra_host_that_is_an_ip(): void
    {
        TestKernel::$extraConfig = ['push_services' => ['extra_hosts' => ['10.0.0.1']]];

        $this->expectExceptionMessage('Cannot accept a push service host');

        self::bootKernel();
    }

    #[Test]
    public function it_should_expose_the_transaction_boundary_on_the_configured_connection(): void
    {
        $this->browser();

        self::assertInstanceOf(DoctrineTransactionBoundary::class, $this->service(TransactionBoundary::class));
    }

    #[Test]
    public function it_should_derive_the_origin_from_the_router_default_uri(): void
    {
        TestKernel::$extraFrameworkConfig = ['router' => ['default_uri' => 'https://App.Example.com:8443/base']];
        self::bootKernel();

        self::assertSame('https://app.example.com:8443', $this->service(Origin::class)->toString());
    }

    #[Test]
    public function it_should_omit_the_default_port_of_the_derived_origin(): void
    {
        TestKernel::$extraFrameworkConfig = ['router' => ['default_uri' => 'https://app.example.com/']];
        self::bootKernel();

        self::assertSame('https://app.example.com', $this->service(Origin::class)->toString());
    }

    #[Test]
    public function it_should_prefer_the_configured_origin(): void
    {
        TestKernel::$extraConfig = ['origin' => 'https://push.example.com'];
        self::bootKernel();

        self::assertSame('https://push.example.com', $this->service(Origin::class)->toString());
    }

    #[Test]
    public function it_should_refuse_an_invalid_origin_on_first_use_only(): void
    {
        TestKernel::$extraConfig = ['origin' => 'http://example.com'];
        self::bootKernel();

        $this->expectException(InvalidValue::class);

        $this->service(Origin::class);
    }

    #[Test]
    public function it_should_send_notifications_with_the_configured_ttl_and_urgency(): void
    {
        TestKernel::$extraConfig = ['delivery' => ['ttl' => 3600, 'urgency' => 'low']];
        $this->browser();
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:7'), TestBrowser::chrome()->address());

        $this->notifier()->send(new Notification('Payment received', ['web_push']), new TestUser(7, 'bob@example.com'));

        self::assertSame(['ttl' => 3600, 'urgency' => 'low', 'topic' => ''], $this->transport()->options[0]->toArray());
    }

    #[Test]
    public function it_should_let_a_notification_override_the_configured_delivery_options(): void
    {
        TestKernel::$extraConfig = ['delivery' => ['ttl' => 3600, 'urgency' => 'low']];
        $this->browser();
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:7'), TestBrowser::chrome()->address());

        $this->notifier()->send(new UrgentNotification('Payment received', ['web_push']), new TestUser(7, 'bob@example.com'));

        self::assertSame(['ttl' => 3600, 'urgency' => 'high', 'topic' => 'payment'], $this->transport()->options[0]->toArray());
    }

    #[Test]
    public function it_should_send_the_test_notification_with_a_short_ttl_and_the_configured_urgency(): void
    {
        TestKernel::$extraConfig = ['delivery' => ['urgency' => 'high']];
        $this->browser();
        $this->service(RegisterSubscription::class)->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());
        $tester = new CommandTester((new Application(self::bootedKernel()))->find('webpush:test'));

        $tester->execute(['subscriber' => 'user:1']);

        self::assertSame(['ttl' => 60, 'urgency' => 'high', 'topic' => ''], $this->transport()->options[0]->toArray());
    }

    #[Test]
    public function it_should_refuse_to_build_with_a_ttl_above_the_protocol_maximum(): void
    {
        TestKernel::$extraConfig = ['delivery' => ['ttl' => DeliveryOptions::MAX_TTL + 1]];

        $this->expectException(InvalidConfigurationException::class);

        self::bootKernel();
    }

    #[Test]
    public function it_should_refuse_to_build_with_a_service_worker_outside_the_origin(): void
    {
        TestKernel::$extraConfig = ['service_worker' => ['register_url' => '//evil.example/sw.js']];

        $this->expectExceptionMessage('Cannot register a service worker outside the application origin');

        self::bootKernel();
    }

    #[Test]
    public function it_should_accept_a_signed_action_url_until_it_expires(): void
    {
        self::bootKernel();

        $actionUrl = $this->service(ActionUrlSigner::class)->sign('page', ['order' => 42], new \DateInterval('PT5M'));

        self::assertTrue($this->service(SymfonyActionUrlSigner::class)->verify(Request::create($actionUrl->toString(), 'POST')));
    }

    #[Test]
    public function it_should_refuse_a_tampered_action_url(): void
    {
        self::bootKernel();
        $actionUrl = $this->service(ActionUrlSigner::class)->sign('page', ['order' => 42], new \DateInterval('PT5M'));

        $tampered = u($actionUrl->toString())->replace('order=42', 'order=43')->toString();

        self::assertFalse($this->service(SymfonyActionUrlSigner::class)->verify(Request::create($tampered, 'POST')));
    }

    #[Test]
    public function it_should_refuse_an_expired_action_url(): void
    {
        self::bootKernel();
        $elapsed = new \DateInterval('PT1M');
        $elapsed->invert = 1;

        $actionUrl = $this->service(ActionUrlSigner::class)->sign('page', ['order' => 42], $elapsed);

        self::assertFalse($this->service(SymfonyActionUrlSigner::class)->verify(Request::create($actionUrl->toString(), 'POST')));
    }

    #[Test]
    public function it_should_refuse_an_url_signed_without_expiry(): void
    {
        self::bootKernel();

        $signed = $this->service(UriSigner::class)->sign('http://localhost/page?order=42');

        self::assertFalse($this->service(SymfonyActionUrlSigner::class)->verify(Request::create($signed, 'POST')));
    }

    #[Test]
    public function it_should_sign_action_urls_within_the_application_origin(): void
    {
        self::bootKernel();

        $actionUrl = $this->service(ActionUrlSigner::class)->sign('page', ['order' => 42], new \DateInterval('PT5M'));

        self::assertTrue($this->service(Origin::class)->isOriginOf($actionUrl->toString()));
        self::assertStringStartsWith('http://localhost/page?', $actionUrl->toString());
    }

    private function notifier(): NotifierInterface
    {
        $notifier = self::getContainer()->get('test.notifier');
        \assert($notifier instanceof NotifierInterface);

        return $notifier;
    }
}
