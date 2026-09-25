<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidKeyGenerator;

/** One VAPID key pair per test process: generating P-256 keys is slow. */
final class VapidKeys
{
    /** @var array{publicKey: string, privateKey: string}|null */
    private static ?array $pair = null;

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public static function pair(): array
    {
        return self::$pair ??= (new VapidKeyGenerator())->generate();
    }
}
