<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Network;

/**
 * A host is unsafe as soon as ONE of its addresses is not public: a mixed answer is
 * exactly what a rebinding attack looks like.
 */
final readonly class ResolvedHostPinning implements HostPinning
{
    public function __construct(
        private DnsResolver $dnsResolver,
        private PublicIpPolicy $publicIpPolicy,
    ) {
    }

    public function planFor(array $hosts): PinningPlan
    {
        $entries = [];
        $unsafe = [];

        foreach (array_values(array_unique($hosts)) as $host) {
            $addresses = array_map($this->publicIpPolicy->normalize(...), $this->dnsResolver->resolve($host));

            if ([] === $addresses || [] !== array_filter($addresses, fn (string $ip): bool => !$this->publicIpPolicy->isPublic($ip))) {
                $unsafe[] = $host;
                continue;
            }

            $pinned = $addresses[0];
            $entries[] = \sprintf('%s:443:%s', $host, false !== filter_var($pinned, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) ? '['.$pinned.']' : $pinned);
        }

        return new PinningPlan($entries, $unsafe);
    }
}
