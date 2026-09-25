<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Vapid;

use Minishlink\WebPush\VAPID;

final readonly class VapidKeyGenerator
{
    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public function generate(): array
    {
        $keys = VAPID::createVapidKeys();

        if (!\is_string($keys['publicKey'] ?? null) || !\is_string($keys['privateKey'] ?? null)) {
            throw new \RuntimeException('Cannot generate a VAPID key pair: openssl lacks P-256 support.');
        }

        return ['publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']];
    }
}
