<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/**
 * A push service and the hosts it serves. A class rather than an enum: the
 * configuration can add hosts (self-hosted push services), an enum cannot be extended.
 */
final readonly class PushService
{
    /**
     * @param list<string> $hosts lowercase hostnames
     */
    private function __construct(
        private string $name,
        private array $hosts,
    ) {
    }

    /**
     * @param list<string> $hosts
     */
    public static function fromNameAndHosts(string $name, array $hosts): self
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $name)) {
            throw InvalidValue::because('Cannot accept a push service name that is not 1 to 32 lowercase alphanumeric characters.');
        }

        foreach ($hosts as $host) {
            self::assertHostname($host);
        }

        return new self($name, $hosts);
    }

    /**
     * Exact match, or suffix anchored on a dot: "push.apple.com" accepts
     * "web.push.apple.com" and refuses "notpush.apple.com".
     */
    public function serves(string $host): bool
    {
        foreach ($this->hosts as $candidate) {
            if ($host === $candidate || u($host)->endsWith('.'.$candidate)) {
                return true;
            }
        }

        return false;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Configuration-supplied hosts are validated at boot: lowercase hostname, no IP,
     * no wildcard, no port.
     */
    public static function assertHostname(string $host): void
    {
        $isHostname = 1 === preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host);

        if (!$isHostname || false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            throw InvalidValue::because('Cannot accept a push service host that is not a lowercase hostname (no IP, wildcard or port).');
        }
    }
}
