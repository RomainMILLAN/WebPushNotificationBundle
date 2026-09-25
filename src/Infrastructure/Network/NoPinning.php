<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Network;

/**
 * dns_pinning: false. Only for environments where the push service is reached
 * through a mandatory proxy — which neutralises pinning anyway (docs/security.md).
 */
final readonly class NoPinning implements HostPinning
{
    public function planFor(array $hosts): PinningPlan
    {
        return new PinningPlan([], []);
    }
}
