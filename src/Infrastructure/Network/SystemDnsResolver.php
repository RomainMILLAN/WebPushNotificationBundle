<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Network;

final readonly class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        $addresses = [];

        foreach (false !== $records ? $records : [] as $record) {
            if (isset($record['ip']) && \is_string($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        if ([] === $addresses) {
            $fallback = @gethostbynamel($host);
            $addresses = false !== $fallback ? $fallback : [];
        }

        return array_values(array_unique($addresses));
    }
}
