<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;

final class AllowedPushServicesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function knownServices(): array
    {
        return [
            'Apple' => ['https://web.push.apple.com/QAbc123', 'apple'],
            'Google' => ['https://fcm.googleapis.com/fcm/send/abc123', 'google'],
            'Mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/abc', 'mozilla'],
            'Microsoft' => ['https://par02p.notify.windows.com/w/?token=abc', 'microsoft'],
        ];
    }

    #[DataProvider('knownServices')]
    #[Test]
    public function it_should_permit_the_known_push_services(string $endpoint, string $service): void
    {
        $allowed = AllowedPushServices::createWithKnownServices();

        self::assertTrue($allowed->permits(PushEndpoint::fromString($endpoint)));
        self::assertSame($service, $allowed->serviceName(PushEndpoint::fromString($endpoint)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownHosts(): array
    {
        return [
            'suffix, not a subdomain' => ['https://fcm.googleapis.com.evil.example/x'],
            'unanchored suffix' => ['https://notpush.apple.com/x'],
            'unknown host' => ['https://evil.example/x'],
        ];
    }

    #[DataProvider('unknownHosts')]
    #[Test]
    public function it_should_refuse_every_other_host(string $endpoint): void
    {
        self::assertFalse(AllowedPushServices::createWithKnownServices()->permits(PushEndpoint::fromString($endpoint)));
    }

    #[Test]
    public function it_should_extend_the_allowlist_with_extra_hosts(): void
    {
        $allowed = AllowedPushServices::createWithKnownServices(['push.example.org']);

        self::assertTrue($allowed->permits(PushEndpoint::fromString('https://eu.push.example.org/abc')));
        self::assertSame('custom', $allowed->serviceNameForHost('eu.push.example.org'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidExtraHosts(): array
    {
        return [
            'IP' => ['10.0.0.1'],
            'wildcard' => ['*.example.org'],
            'port' => ['push.example.org:8443'],
            'uppercase' => ['Push.Example.org'],
            'single label' => ['localhost'],
        ];
    }

    #[DataProvider('invalidExtraHosts')]
    #[Test]
    public function it_should_validate_extra_hosts(string $host): void
    {
        $this->expectException(InvalidValue::class);

        AllowedPushServices::createWithKnownServices([$host]);
    }
}
