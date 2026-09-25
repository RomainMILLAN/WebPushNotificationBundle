<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Clock;

use Psr\Clock\ClockInterface;

/** PSR-20 fallback when the framework provides no clock service. */
final readonly class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
