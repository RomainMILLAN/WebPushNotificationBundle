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
    public function it_should_register_records_the_fact(): void
    {
        $subscription = $this->registered(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome());

        self::assertTrue($subscription->isActive());
        self::assertEquals([SubscriptionRegistered::class], $this->eventClasses($subscription));
    }

    #[Test]
    public function it_should_active_same_owner_renews_whatever_the_keys_key_rotation(): void
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
    public function it_should_active_other_owner_with_the_same_auth_is_reassigned_shared_browser(): void
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
    public function it_should_active_other_owner_with_another_auth_is_refused_leaked_endpoint(): void
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
    public function it_should_anonymous_to_identified_with_proof_is_a_claim(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(new AnonymousOwner(), $browser));

        self::assertSame(ClaimOutcome::Reassigned, $subscription->claim(IdentifiedOwner::fromSubscriberId('user:1'), $browser->address(), $this->now));
    }

    #[Test]
    public function it_should_identified_to_anonymous_is_refused_no_downgrade(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(IdentifiedOwner::fromSubscriberId('user:1'), $browser));

        self::assertSame(ClaimOutcome::RefusedDowngrade, $subscription->claim(new AnonymousOwner(), $browser->address(), $this->now));
    }

    #[Test]
    public function it_should_same_anonymous_owner_proves_nothing_key_change_needs_the_auth(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(new AnonymousOwner(), $browser));

        self::assertSame(ClaimOutcome::RefusedAuthMismatch, $subscription->claim(new AnonymousOwner(), $browser->forgedAddress(), $this->now));
        self::assertTrue($subscription->isProvenBy($browser->auth()));
        self::assertSame(ClaimOutcome::Renewed, $subscription->claim(new AnonymousOwner(), $browser->address(), $this->now));
    }

    #[Test]
    public function it_should_retired_same_owner_is_reactivated(): void
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
    public function it_should_retired_other_owner_with_the_same_auth_is_reactivated_for_them(): void
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
    public function it_should_retired_other_owner_with_another_auth_is_refused(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(IdentifiedOwner::fromSubscriberId('user:1'), $browser));
        $subscription->expire($this->now);

        self::assertSame(ClaimOutcome::RefusedAuthMismatch, $subscription->claim(IdentifiedOwner::fromSubscriberId('user:2'), $browser->forgedAddress(), $this->now));
        self::assertFalse($subscription->isActive());
    }

    #[Test]
    public function it_should_retired_identified_to_anonymous_is_refused(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->released($this->registered(IdentifiedOwner::fromSubscriberId('user:1'), $browser));
        $subscription->expire($this->now);

        self::assertSame(ClaimOutcome::RefusedDowngrade, $subscription->claim(new AnonymousOwner(), $browser->address(), $this->now));
    }

    #[Test]
    public function it_should_claiming_with_another_endpoint_is_a_programming_error(): void
    {
        $subscription = $this->registered(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('one'));

        $this->expectException(InvariantViolated::class);

        $subscription->claim(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('two')->address(), $this->now);
    }

    #[Test]
    public function it_should_a_retired_subscription_cannot_be_retired_twice(): void
    {
        $subscription = $this->registered(IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome());
        $subscription->expire($this->now);

        $this->expectException(InvariantViolated::class);

        $subscription->evict($this->now);
    }

    #[Test]
    public function it_should_is_ordered_by_last_registration(): void
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
