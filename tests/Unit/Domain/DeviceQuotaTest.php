<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Subscription\DeviceQuota;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\OwnerSubscriptions;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Testing\TestBrowser;

final class DeviceQuotaTest extends TestCase
{
    #[Test]
    public function it_should_nothing_is_evicted_below_the_quota(): void
    {
        self::assertSame([], DeviceQuota::createAllowing(3)->evictionsToFitOneMore($this->devices(2)));
    }

    #[Test]
    public function it_should_the_least_recently_registered_makes_room(): void
    {
        $devices = $this->devices(3);
        $evicted = DeviceQuota::createAllowing(3)->evictionsToFitOneMore($devices);

        self::assertCount(1, $evicted);
        self::assertSame(str_pad('1', 32, '0', \STR_PAD_LEFT), $evicted[0]->id()->toString());
    }

    #[Test]
    public function it_should_a_lowered_quota_evicts_the_whole_excess(): void
    {
        self::assertCount(4, DeviceQuota::createAllowing(2)->evictionsToFitOneMore($this->devices(5)));
    }

    #[Test]
    public function it_should_a_quota_is_at_least_one(): void
    {
        $this->expectException(InvalidValue::class);

        DeviceQuota::createAllowing(0);
    }

    private function devices(int $count): OwnerSubscriptions
    {
        $devices = [];
        $at = new \DateTimeImmutable('2026-01-01');

        for ($i = 1; $i <= $count; ++$i) {
            $devices[] = Subscription::register(
                SubscriptionId::fromString(str_pad((string) $i, 32, '0', \STR_PAD_LEFT)),
                IdentifiedOwner::fromSubscriberId('user:1'),
                TestBrowser::chrome('device-'.$i)->address(),
                $at->modify('+'.$i.' days'),
            );
        }

        // Stored order must not matter.
        return new OwnerSubscriptions(array_reverse($devices));
    }
}
