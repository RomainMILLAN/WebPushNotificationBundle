<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\Port\SubscriptionReadModel;
use RomainMillan\WebPushNotification\Application\Port\TransactionBoundary;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionAlreadyExists;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionNotFound;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Owner;
use RomainMillan\WebPushNotification\Domain\Subscription\PurgeableSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * The executable contract of the persistence ports — the Liskov test every adapter
 * (Doctrine, Eloquent, yours) must pass. Extend it and provide the adapters.
 */
abstract class SubscriptionRepositoryContract extends TestCase
{
    abstract protected function repository(): SubscriptionRepository&PurgeableSubscriptions&SubscriptionReadModel;

    abstract protected function transactionBoundary(): TransactionBoundary;

    #[Test]
    public function it_should_a_saved_subscription_is_reconstituted_with_its_state(): void
    {
        $browser = TestBrowser::chrome();
        $this->save($this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), $browser));

        $restored = $this->repository()->get($this->id(1));

        self::assertTrue($restored->isActive());
        self::assertTrue($restored->isOwnedBy(IdentifiedOwner::fromSubscriberId('user:1')));
        self::assertTrue($restored->isProvenBy($browser->auth()));
    }

    #[Test]
    public function it_should_an_unknown_id_is_not_found(): void
    {
        $this->expectException(SubscriptionNotFound::class);

        $this->repository()->get($this->id(404));
    }

    #[Test]
    public function it_should_lookups_by_fingerprint_include_retired_subscriptions(): void
    {
        $browser = TestBrowser::chrome();
        $subscription = $this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), $browser);
        $subscription->expire(new \DateTimeImmutable('2026-02-01'));
        $this->save($subscription);

        self::assertTrue($this->repository()->hasFingerprint($browser->address()->fingerprint()));
        self::assertFalse($this->repository()->getByFingerprint($browser->address()->fingerprint())->isActive());
        self::assertFalse($this->repository()->hasFingerprint(TestBrowser::chrome('other')->address()->fingerprint()));
    }

    #[Test]
    public function it_should_the_same_endpoint_under_another_id_violates_the_unique_index(): void
    {
        $browser = TestBrowser::chrome();
        $this->save($this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), $browser));

        $this->expectException(SubscriptionAlreadyExists::class);

        $this->save($this->subscription(2, IdentifiedOwner::fromSubscriberId('user:2'), $browser));
    }

    #[Test]
    public function it_should_saving_twice_updates(): void
    {
        $subscription = $this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome());
        $this->save($subscription);
        $subscription->expire(new \DateTimeImmutable('2026-02-01'));
        $this->save($subscription);

        self::assertFalse($this->repository()->get($this->id(1))->isActive());
    }

    #[Test]
    public function it_should_owned_by_returns_the_active_subscriptions_of_an_identified_owner_only(): void
    {
        $alice = IdentifiedOwner::fromSubscriberId('user:1');
        $this->save($this->subscription(1, $alice, TestBrowser::chrome('a')));
        $retired = $this->subscription(2, $alice, TestBrowser::chrome('b'));
        $retired->evict(new \DateTimeImmutable('2026-02-01'));
        $this->save($retired);
        $this->save($this->subscription(3, IdentifiedOwner::fromSubscriberId('user:2'), TestBrowser::chrome('c')));
        $this->save($this->subscription(4, new AnonymousOwner(), TestBrowser::chrome('d')));

        self::assertCount(1, $this->repository()->ownedBy($alice));
        self::assertCount(0, $this->repository()->ownedBy(new AnonymousOwner()));
        self::assertSame(1, $this->repository()->countActiveAnonymous());
    }

    #[Test]
    public function it_should_the_owner_filter_is_part_of_the_query(): void
    {
        $this->save($this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()));

        self::assertTrue($this->repository()->getOwnedSubscription(IdentifiedOwner::fromSubscriberId('user:1'), $this->id(1))->isActive());

        $this->expectException(SubscriptionNotFound::class);
        $this->repository()->getOwnedSubscription(IdentifiedOwner::fromSubscriberId('user:2'), $this->id(1));
    }

    #[Test]
    public function it_should_get_many_omits_missing_ids(): void
    {
        $this->save($this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('a')));
        $this->save($this->subscription(2, IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('b')));

        self::assertCount(2, $this->repository()->getMany([$this->id(1), $this->id(2), $this->id(3)]));
    }

    #[Test]
    public function it_should_active_subscriptions_come_in_batches(): void
    {
        foreach (range(1, 5) as $i) {
            $this->save($this->subscription($i, IdentifiedOwner::fromSubscriberId('user:'.$i), TestBrowser::chrome('device-'.$i)));
        }

        $sizes = array_map(count(...), iterator_to_array($this->repository()->activeInBatches(2), false));

        self::assertSame([2, 2, 1], $sizes);
    }

    #[Test]
    public function it_should_bulk_purges(): void
    {
        $retired = $this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('a'));
        $retired->expire(new \DateTimeImmutable('2026-01-01'));
        $this->save($retired);
        $this->save($this->subscription(2, new AnonymousOwner(), TestBrowser::chrome('b'), '2026-01-01'));
        $this->save($this->subscription(3, IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('c')));

        self::assertSame(1, $this->repository()->deleteRetiredBefore(new \DateTimeImmutable('2026-06-01')));
        self::assertSame(1, $this->repository()->deleteStaleAnonymousBefore(new \DateTimeImmutable('2026-06-01')));
        self::assertSame(1, $this->repository()->deleteAllOwnedBy(IdentifiedOwner::fromSubscriberId('user:1')));
        self::assertSame(0, $this->repository()->deleteAllOwnedBy(new AnonymousOwner()));
    }

    #[Test]
    public function it_should_the_read_model_lists_devices_without_secrets(): void
    {
        $browser = TestBrowser::safari('secret-token');
        $this->save($this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), $browser));

        $views = $this->repository()->listFor(IdentifiedOwner::fromSubscriberId('user:1'));

        self::assertCount(1, $views);
        self::assertSame('apple', $views[0]->pushService);
        self::assertTrue($views[0]->period->isActive());
        self::assertStringNotContainsString('secret-token', print_r($views, true));
        self::assertSame([], $this->repository()->listFor(new AnonymousOwner()));
    }

    #[Test]
    public function it_should_removal(): void
    {
        $subscription = $this->subscription(1, IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome());
        $this->save($subscription);
        $this->transactionBoundary()->run(fn () => $this->repository()->remove($subscription));

        self::assertFalse($this->repository()->hasFingerprint(TestBrowser::chrome()->address()->fingerprint()));
    }

    protected function id(int $sequence): SubscriptionId
    {
        return SubscriptionId::fromString(str_pad(dechex($sequence), 32, '0', \STR_PAD_LEFT));
    }

    private function subscription(int $sequence, Owner $owner, TestBrowser $browser, string $registeredAt = '2026-03-01'): Subscription
    {
        return Subscription::register($this->id($sequence), $owner, $browser->address(), new \DateTimeImmutable($registeredAt));
    }

    private function save(Subscription $subscription): void
    {
        $this->transactionBoundary()->run(fn () => $this->repository()->save($subscription));
    }
}
