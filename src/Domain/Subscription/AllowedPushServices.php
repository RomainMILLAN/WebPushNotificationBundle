<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * Specification: is this endpoint served by a push service we agree to call?
 *
 * An allowlist, never a blocklist: four push services exist in the world, whereas the
 * internal addresses to forbid are countless. Checked at registration AND at delivery
 * (a host removed from the configuration stops deliveries at once), never at
 * reconstitution. It is not the only SSRF barrier: delivery also refuses non-public
 * IPs and pins DNS.
 */
final readonly class AllowedPushServices
{
    /**
     * @param list<PushService> $services
     */
    private function __construct(
        private array $services,
    ) {
    }

    /**
     * @param list<string> $extraHosts self-hosted push services, validated as hostnames
     */
    public static function createWithKnownServices(array $extraHosts = []): self
    {
        $services = [
            PushService::fromNameAndHosts('apple', ['push.apple.com']),
            PushService::fromNameAndHosts('google', ['fcm.googleapis.com', 'android.googleapis.com']),
            PushService::fromNameAndHosts('mozilla', ['updates.push.services.mozilla.com']),
            PushService::fromNameAndHosts('microsoft', ['notify.windows.com']),
        ];

        if ([] !== $extraHosts) {
            $services[] = PushService::fromNameAndHosts('custom', $extraHosts);
        }

        return new self($services);
    }

    public function permits(PushEndpoint $endpoint): bool
    {
        return 'unknown' !== $this->serviceName($endpoint);
    }

    /** The loggable diagnostic — "apple" — never the capability URL. */
    public function serviceName(PushEndpoint $endpoint): string
    {
        return $this->serviceNameForHost($endpoint->host());
    }

    public function serviceNameForHost(string $host): string
    {
        foreach ($this->services as $service) {
            if ($service->serves($host)) {
                return $service->name();
            }
        }

        return 'unknown';
    }
}
