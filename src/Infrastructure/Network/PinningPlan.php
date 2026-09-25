<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Network;

final readonly class PinningPlan
{
    /**
     * @param list<string> $resolveEntries CURLOPT_RESOLVE entries "host:443:ip"
     * @param list<string> $unsafeHosts    hosts that resolve to a non-public address or not at all
     */
    public function __construct(
        private array $resolveEntries,
        private array $unsafeHosts,
    ) {
    }

    public function isUnsafe(string $host): bool
    {
        return \in_array($host, $this->unsafeHosts, true);
    }

    /**
     * @return list<string>
     */
    public function resolveEntries(): array
    {
        return $this->resolveEntries;
    }
}
