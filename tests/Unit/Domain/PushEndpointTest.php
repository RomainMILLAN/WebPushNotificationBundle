<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidPushEndpoint;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;

final class PushEndpointTest extends TestCase
{
    /**
     * Real-world shapes: the canonical round trip must return the very same string,
     * or a legitimate subscription would be refused (or worse, rewritten and expired).
     *
     * @return array<string, array{string}>
     */
    public static function realEndpoints(): array
    {
        return [
            'FCM' => ['https://fcm.googleapis.com/fcm/send/dM3v2Yx8Sls:APA91bHPRgkF3JUikC4ENAHEeMrd41Zxv3hVZjC9KtT8OvPVGJ-hQMRKRrZuJAEcl7B338qju59zJMjw2DELjzEvxwYv7hH5Ynpc1ODQ0aT4U4OFEeco8ohsN5PjL1iC2dNtk2BAokeMCg2ZXKqpc8FXKmhX94kIxQ'],
            'Apple' => ['https://web.push.apple.com/QGuQyavXutnMH8Fz0p-8BY3Yf9pWg-vA1k2A7UZl'],
            'Mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAAABkRvB_example-token_with-dashes'],
            'WNS' => ['https://wns2-par02p.notify.windows.com/w/?token=BQYAAAB%2bXYZ%3d'],
        ];
    }

    #[DataProvider('realEndpoints')]
    #[Test]
    public function it_should_accepts_real_endpoints_and_round_trips_them_unchanged(string $endpoint): void
    {
        self::assertSame($endpoint, PushEndpoint::fromString($endpoint)->toString());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedEndpoints(): array
    {
        return [
            'http' => ['http://fcm.googleapis.com/x'],
            'IP literal' => ['https://127.0.0.1/_cluster/health'],
            'IPv6 literal' => ['https://[::1]/x'],
            'userinfo disguising the host' => ['https://fcm.googleapis.com@evil.example/x'],
            'explicit port' => ['https://fcm.googleapis.com:9200/x'],
            'not an URL' => ['not-an-url'],
            'surrounding whitespace' => [' https://fcm.googleapis.com/x'],
            'control character' => ["https://fcm.googleapis.com/x\x00y"],
            'backslash' => ['https://fcm.googleapis.com\\@evil.example/x'],
            'uppercase host (not canonical)' => ['https://FCM.googleapis.com/x'],
            'fragment (not canonical)' => ['https://fcm.googleapis.com/x#frag'],
        ];
    }

    #[DataProvider('refusedEndpoints')]
    #[Test]
    public function it_should_refuses_what_is_not_a_canonical_https_endpoint(string $endpoint): void
    {
        $this->expectException(InvalidPushEndpoint::class);

        PushEndpoint::fromString($endpoint);
    }

    #[Test]
    public function it_should_refuses_an_endpoint_longer_than_the_column(): void
    {
        $this->expectException(InvalidPushEndpoint::class);

        PushEndpoint::fromString('https://fcm.googleapis.com/fcm/send/'.str_repeat('a', PushEndpoint::MAX_LENGTH));
    }

    #[Test]
    public function it_should_the_fingerprint_identifies_the_device(): void
    {
        $endpoint = PushEndpoint::fromString('https://fcm.googleapis.com/fcm/send/abc123');

        self::assertSame(hash('sha256', 'https://fcm.googleapis.com/fcm/send/abc123'), $endpoint->fingerprint()->toString());
        self::assertSame(12, \strlen($endpoint->fingerprint()->short()));
    }

    #[Test]
    public function it_should_never_dumps_the_capability_url(): void
    {
        $endpoint = PushEndpoint::fromString('https://fcm.googleapis.com/fcm/send/secret-token');

        self::assertStringNotContainsString('secret-token', print_r($endpoint, true));
        self::assertStringNotContainsString('secret-token', var_export($endpoint->__debugInfo(), true));
    }

    #[Test]
    public function it_should_the_exception_message_never_repeats_the_endpoint(): void
    {
        try {
            PushEndpoint::fromString('https://evil.example:8080/secret-token');
            self::fail('The endpoint should have been refused.');
        } catch (InvalidPushEndpoint $refused) {
            self::assertStringNotContainsString('secret-token', $refused->getMessage());
        }
    }
}
