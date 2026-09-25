<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Network;

/** Port for tests: delivery must be testable without a network. */
interface DnsResolver
{
    /**
     * @return list<string> every A and AAAA address of $host; empty when it does not resolve
     */
    public function resolve(string $host): array;
}
