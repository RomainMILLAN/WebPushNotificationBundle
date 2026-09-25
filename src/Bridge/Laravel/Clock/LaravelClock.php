<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Clock;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;

/** Carbon's clock, so that Laravel's travel() / Carbon::setTestNow() drive the core. */
final readonly class LaravelClock implements ClockInterface
{
    public function now(): CarbonImmutable
    {
        return Carbon::now()->toImmutable();
    }
}
