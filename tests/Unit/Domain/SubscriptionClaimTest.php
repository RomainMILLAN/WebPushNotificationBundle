<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Domain\Exception\InvariantViolated;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\ClaimOutcome;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionReactivated;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionReassigned;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionRegistered;
use RomainMillan\WebPushNotification\Domain\Subscription\Event\SubscriptionRenewed;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Testing\TestBrowser;

/**
 * The registration matrix, row by row, at the aggregate — its only entry point.
 */
final class SubscriptionClaimTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-23 10:00:00');
    }

    #[Test]
    public function it_should_record_the_registration_fact(): void
    {
        $subscription = $this->registered(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome());

        self::assertTrue($subscription->isActive());
        self::assertEquals([SubscriptionRegistered::class], $this->eventClasses($subscription));
    }

    #[Test]
    public function it_should_renew_an_active_device_of_the_same_owner_whatever_the_keys(): void
    {
        $browser = TestBrowser::safari();
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $subscription = $this->released($this->registered($alice, $browser));

        $browser->rotateKeys();
        $outcome = $subscription->claim($alice, $browser->address(), $this->now->modify('+1 day'));

        self::assertSame(ClaimOutcome::Renewed, $outcome);
        self::assertTrue($subscription->isProvenBy($browser->auth()));
        self::assertEquals([SubscriptionRenewed::class], $this->eventClasses($subscription));
    }

    #[Test]
    public function it_should_reassign_an_active_device_to_another_owner_proving_the_same_auth(): void
    {
        $browser = TestBrowser::chrome();
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $bob = IdentifiedOwner::fromSubscriberId('user:2');
        $subscription = $this->released($this->registered($alice, $browser));

        $outcome = $subscription->claim($bob, $browser->address(), $this->now);

        self::assertSame(ClaimOutcome::Reassigned, $outcome);
        self::assertTrue($subscription->isOwnedBy($bob));
        self::assertEquals([SubscriptionReassigned::class], $this->eventClasses($subscription));
    }

    #[Test]
    public function it_should_refuse_another_owner_presenting_another_auth_on_an_active_device(): void
    {
        $browser = TestBrowser::chrome();
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $subscription = $this->released($this->registered($alice, $browser));

        $outcome = $subscription->claim(IdentifiedOwner::fromSubscriberId('user:666'), $browser->forgedAddress(), $this->now);

        self::assertSame(ClaimOutcome::RefusedAuthMismatch, $outcome);
        self::assertTrue($subscription->isOwnedBy($alice));
        self::assertTrue($subscription->isProvenBy($browser->auth()), 'The legitimate keys must be untouched.');
        self::assertSame([], $subscription->releaseEvents());
    }

    #[Test]
    public function it_should_let_an_identified_owner_claim_an_anonymous_device_with_proof(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(new AnonymousOwner(), $browser));

        self::assertSame(ClaimOutcome::Reassigned, $subscription->claim(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address(), $this->now));
    }

    #[Test]
    public function it_should_refuse_to_downgrade_an_active_identified_device_to_anonymous(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(IdentifiedOwner::fromSubscriberId('user:1'), $browser));

        self::assertSame(ClaimOutcome::RefusedDowngrade, $subscription->claim(new AnonymousOwner(), $browser->address(), $this->now));
    }

    #[Test]
    public function it_should_require_the_auth_for_an_anonymous_key_change(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(new AnonymousOwner(), $browser));

        self::assertSame(ClaimOutcome::RefusedAuthMismatch, $subscription->claim(new AnonymousOwner(), $browser->forgedAddress(), $this->now));
        self::assertTrue($subscription->isProvenBy($browser->auth()));
        self::assertSame(ClaimOutcome::Renewed, $subscription->claim(new AnonymousOwner(), $browser->address(), $this->now));
    }

    #[Test]
    public function it_should_reactivate_a_retired_device_of_the_same_owner(): void
    {
        $browser = TestBrowser::chrome();
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $subscription = $this->released($this->registered($alice, $browser));
        $subscription->expire($this->now);
        $subscription->releaseEvents();

        self::assertSame(ClaimOutcome::Reactivated, $subscription->claim($alice, $browser->address(), $this->now->modify('+1 hour')));
        self::assertTrue($subscription->isActive());
        self::assertEquals([SubscriptionReactivated::class], $this->eventClasses($subscription));
    }

    #[Test]
    public function it_should_reactivate_a_retired_device_for_another_owner_proving_the_same_auth(): void
    {
        $browser = TestBrowser::chrome();
        $bob = IdentifiedOwner::fromSubscriberId('user:2');
        $subscription = $this->released($this->registered(IdentifiedOwner::fromSubscriberId('user:1'), $browser));
        $subscription->evict($this->now);
        $subscription->releaseEvents();

        self::assertSame(ClaimOutcome::Reactivated, $subscription->claim($bob, $browser->address(), $this->now));
        self::assertTrue($subscription->isOwnedBy($bob));
        self::assertEquals([SubscriptionReactivated::class, SubscriptionReassigned::class], $this->eventClasses($subscription));
    }

    #[Test]
    public function it_should_refuse_another_owner_presenting_another_auth_on_a_retired_device(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(IdentifiedOwner::fromSubscriberId('user:1'), $browser));
        $subscription->expire($this->now);

        self::assertSame(ClaimOutcome::RefusedAuthMismatch, $subscription->claim(IdentifiedOwner::fromSubscriberId('user:2'), $browser->forgedAddress(), $this->now));
        self::assertFalse($subscription->isActive());
    }

    #[Test]
    public function it_should_refuse_to_downgrade_a_retired_identified_device_to_anonymous(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(IdentifiedOwner::fromSubscriberId('user:1'), $browser));
        $subscription->expire($this->now);

        self::assertSame(ClaimOutcome::RefusedDowngrade, $subscription->claim(new AnonymousOwner(), $browser->address(), $this->now));
    }

    #[Test]
    public function it_should_treat_a_claim_with_another_endpoint_as_a_programming_error(): void
    {
        $subscription = $this->registered(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('one'));

        $this->expectException(InvariantViolated::class);

        $subscription->claim(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('two')->address(), $this->now);
    }

    #[Test]
    public function it_should_refuse_to_retire_a_subscription_twice(): void
    {
        $subscription = $this->registered(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome());
        $subscription->expire($this->now);

        $this->expectException(InvariantViolated::class);

        $subscription->evict($this->now);
    }

    #[Test]
    public function it_should_order_subscriptions_by_last_registration(): void
    {
        $older = $this->registered(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('a'));
        $this->now = $this->now->modify('+1 minute');
        $newer = $this->registered(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('b'));

        self::assertTrue($older->isRegisteredBefore($newer));
        self::assertFalse($newer->isRegisteredBefore($older));
    }

    private function registered(IdentifiedOwner|AnonymousOwner $owner, TestBrowser $browser): Subscription
    {
        return Subscription::register(SubscriptionId::fromString(bin2hex(random_bytes(16))), $owner, $browser->address(), $this->now);
    }

    private function released(Subscription $subscription): Subscription
    {
        $subscription->releaseEvents();

        return $subscription;
    }

    /**
     * @return list<class-string>
     */
    private function eventClasses(Subscription $subscription): array
    {
        return array_map(static fn (object $event): string => $event::class, $subscription->releaseEvents());
    }
}
