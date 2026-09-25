<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(
        private \DateTimeImmutable $now = new \DateTimeImmutable('2026-09-23 10:00:00'),
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
