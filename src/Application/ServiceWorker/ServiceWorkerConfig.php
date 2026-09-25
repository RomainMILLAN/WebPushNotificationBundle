<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\ServiceWorker;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Message\AssetUrl;
use RomainMillan\WebPushNotification\Domain\Subscription\PushService;

use function Symfony\Component\String\u;

/**
 * What the prebuilt service worker needs to know about the application. Static
 * configuration only: the script must never depend on who requests it.
 */
final readonly class ServiceWorkerConfig
{
    /**
     * @param array{fallbackTitle: string, icon: string, badge: string} $appearance
     * @param list<string>                                              $clickPrefixes
     * @param list<string>                                              $assetHosts
     */
    private function __construct(
        private array $appearance,
        private array $clickPrefixes,
        private array $assetHosts,
        private string $stateCache,
    ) {
    }

    /**
     * @param list<string> $clickPrefixes
     * @param list<string> $assetHosts
     */
    public static function fromSettings(string $fallbackTitle, string $icon, string $badge, array $clickPrefixes, array $assetHosts, string $stateCache): self
    {
        if (u($fallbackTitle)->trim()->isEmpty() || u($fallbackTitle)->length() > 120) {
            throw InvalidValue::because('Cannot accept a service worker fallback title that is blank or longer than 120 characters.');
        }

        foreach ([$icon, $badge] as $url) {
            if ('' !== $url) {
                AssetUrl::fromString($url);
            }
        }

        foreach ($clickPrefixes as $prefix) {
            if (!u($prefix)->startsWith('/') || u($prefix)->startsWith('//') || u($prefix)->containsAny(['\\', ' '])) {
                throw InvalidValue::because('Cannot accept a click prefix that is not an in-origin path such as "/app/".');
            }
        }

        foreach ($assetHosts as $host) {
            PushService::assertHostname($host);
        }

        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $stateCache)) {
            throw InvalidValue::because('Cannot accept a state cache name that is not 1 to 64 lowercase alphanumeric characters or dashes.');
        }

        return new self(['fallbackTitle' => $fallbackTitle, 'icon' => $icon, 'badge' => $badge], $clickPrefixes, $assetHosts, $stateCache);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->appearance + ['clickPrefixes' => $this->clickPrefixes, 'assetHosts' => $this->assetHosts, 'stateCache' => $this->stateCache];
    }
}
