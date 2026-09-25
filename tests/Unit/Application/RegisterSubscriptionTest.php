<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\RetirementPolicy;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionAlreadyExists;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\ClaimOutcome;
use RomainMillan\WebPushNotification\Domain\Subscription\ContentEncoding;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionEvicted;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionReassigned;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionRegistered;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionKeys;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Support\TestApplication;

final class RegisterSubscriptionTest extends TestCase
{
    #[Test]
    public function it_should_a_new_browser_is_registered_and_the_event_published_after_commit(): void
    {
        $app = new TestApplication();

        $outcome = $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());

        self::assertSame(ClaimOutcome::Registered, $outcome);
        self::assertSame(1, $app->subscriptions->countRows());
        self::assertSame([SubscriptionRegistered::class], $app->events->dispatchedClasses());
    }

    #[Test]
    public function it_should_an_endpoint_outside_the_allowlist_is_refused_before_anything(): void
    {
        $app = new TestApplication();
        $evil = new PushAddress(PushEndpoint::fromString('https://169-254-169-254.nip.io/latest/meta-data'), SubscriptionKeys::fromStrings(str_repeat('A', 87), str_repeat('B', 22)), ContentEncoding::Aes128Gcm);

        self::assertSame(ClaimOutcome::RefusedHostNotAllowed, $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), $evil));
        self::assertSame(0, $app->subscriptions->countRows());
    }

    #[Test]
    public function it_should_registering_again_renews_instead_of_duplicating(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $alice = IdentifiedOwner::fromSubscriberId('user:1');

        $app->registerSubscription()->register($alice, $browser->address());
        $browser->rotateKeys();

        self::assertSame(ClaimOutcome::Renewed, $app->registerSubscription()->register($alice, $browser->address()));
        self::assertSame(1, $app->subscriptions->countRows());
    }

    #[Test]
    public function it_should_the_quota_evicts_the_least_recently_registered_device(): void
    {
        $app = new TestApplication(maxPerSubscriber: 2);
        $alice = IdentifiedOwner::fromSubscriberId('user:1');

        foreach (['first', 'second', 'third'] as $device) {
            $app->registerSubscription()->register($alice, TestBrowser::chrome($device)->address());
            $app->clock->advance('+1 minute');
        }

        $active = iterator_to_array($app->subscriptions->ownedBy($alice), false);
        self::assertCount(2, $active);
        self::assertSame(3, $app->subscriptions->countRows(), 'deactivate policy keeps the evicted row');
        self::assertContains(SubscriptionEvicted::class, $app->events->dispatchedClasses());
        self::assertFalse($app->subscriptions->getByFingerprint(TestBrowser::chrome('first')->address()->fingerprint())->isActive());
    }

    #[Test]
    public function it_should_a_renewal_refreshes_the_eviction_rank(): void
    {
        $app = new TestApplication(maxPerSubscriber: 2);
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $first = TestBrowser::chrome('first');

        $app->registerSubscription()->register($alice, $first->address());
        $app->clock->advance('+1 minute');
        $app->registerSubscription()->register($alice, TestBrowser::chrome('second')->address());
        $app->clock->advance('+1 minute');
        $app->registerSubscription()->register($alice, $first->address());
        $app->clock->advance('+1 minute');
        $app->registerSubscription()->register($alice, TestBrowser::chrome('third')->address());

        self::assertTrue($app->subscriptions->getByFingerprint($first->address()->fingerprint())->isActive());
        self::assertFalse($app->subscriptions->getByFingerprint(TestBrowser::chrome('second')->address()->fingerprint())->isActive());
    }

    #[Test]
    public function it_should_the_delete_policy_removes_evicted_devices(): void
    {
        $app = new TestApplication(retirementPolicy: RetirementPolicy::Delete, maxPerSubscriber: 1);
        $alice = IdentifiedOwner::fromSubscriberId('user:1');

        $app->registerSubscription()->register($alice, TestBrowser::chrome('first')->address());
        $app->clock->advance('+1 minute');
        $app->registerSubscription()->register($alice, TestBrowser::chrome('second')->address());

        self::assertSame(1, $app->subscriptions->countRows());
        self::assertContains(SubscriptionEvicted::class, $app->events->dispatchedClasses());
    }

    #[Test]
    public function it_should_the_quota_applies_on_reactivation(): void
    {
        $app = new TestApplication(maxPerSubscriber: 1);
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $first = TestBrowser::chrome('first');

        $app->registerSubscription()->register($alice, $first->address());
        $app->clock->advance('+1 minute');
        $app->registerSubscription()->register($alice, TestBrowser::chrome('second')->address());
        $app->clock->advance('+1 minute');

        self::assertSame(ClaimOutcome::Reactivated, $app->registerSubscription()->register($alice, $first->address()));
        self::assertCount(1, $app->subscriptions->ownedBy($alice));
    }

    #[Test]
    public function it_should_a_shared_browser_changes_hands_with_proof_and_the_new_owner_quota_applies(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();

        $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address());
        $outcome = $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:2'), $browser->address());

        self::assertSame(ClaimOutcome::Reassigned, $outcome);
        self::assertCount(0, $app->subscriptions->ownedBy(IdentifiedOwner::fromSubscriberId('user:1')));
        self::assertCount(1, $app->subscriptions->ownedBy(IdentifiedOwner::fromSubscriberId('user:2')));
        self::assertContains(SubscriptionReassigned::class, $app->events->dispatchedClasses());
    }

    #[Test]
    public function it_should_a_leaked_endpoint_cannot_detach_a_device(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $alice = IdentifiedOwner::fromSubscriberId('user:1');

        $app->registerSubscription()->register($alice, $browser->address());

        self::assertSame(ClaimOutcome::RefusedAuthMismatch, $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:666'), $browser->forgedAddress()));
        self::assertTrue($app->subscriptions->getByFingerprint($browser->address()->fingerprint())->isOwnedBy($alice));
        self::assertTrue($app->subscriptions->getByFingerprint($browser->address()->fingerprint())->isProvenBy($browser->auth()));
    }

    #[Test]
    public function it_should_anonymous_subscriptions_stop_at_the_global_cap_without_evicting_strangers(): void
    {
        $app = new TestApplication(maxAnonymous: 2);

        $app->registerSubscription()->register(new AnonymousOwner(), TestBrowser::chrome('a')->address());
        $app->registerSubscription()->register(new AnonymousOwner(), TestBrowser::chrome('b')->address());

        self::assertSame(ClaimOutcome::RefusedAnonymousCap, $app->registerSubscription()->register(new AnonymousOwner(), TestBrowser::chrome('c')->address()));
        self::assertSame(2, $app->subscriptions->countActiveAnonymous());
    }

    #[Test]
    public function it_should_a_lost_race_on_the_unique_index_is_replayed_as_an_upsert(): void
    {
        $app = new TestApplication();
        $browser = TestBrowser::chrome();
        $alice = IdentifiedOwner::fromSubscriberId('user:1');

        // Simulates a concurrent registration inserting the row between our read and our save.
        $concurrent = new TestApplication();
        $concurrent->registerSubscription()->register($alice, $browser->address());
        $racing = new RacingRepository($app->subscriptions, $concurrent->subscriptions->getByFingerprint($browser->address()->fingerprint()));

        $outcome = $app->withRepository($racing)->registerSubscription()->register($alice, $browser->address());

        self::assertSame(ClaimOutcome::Renewed, $outcome);
        self::assertSame(1, $racing->saveAttempts - 1, 'one failed save, then the replay');
    }

    #[Test]
    public function it_should_nothing_is_published_when_the_transaction_rolls_back(): void
    {
        $app = new TestApplication();

        try {
            $app->transactionBoundary->run(static function () use ($app): never {
                $app->registerSubscription()->register(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address());

                throw new SubscriptionAlreadyExists('outer failure');
            });
        } catch (SubscriptionAlreadyExists) {
        }

        self::assertSame([], $app->events->dispatched);
    }
}
