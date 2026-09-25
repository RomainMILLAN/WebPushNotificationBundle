<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Network;

/**
 * Anti DNS-rebinding: resolve once, check, and force cURL to connect to the checked
 * address (CURLOPT_RESOLVE) — the hostname still drives the Host header and TLS SNI.
 */
interface HostPinning
{
    /**
     * @param list<string> $hosts
     */
    public function planFor(array $hosts): PinningPlan;
}
