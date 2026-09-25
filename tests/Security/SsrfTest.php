<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Security;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\WebPushClientFactory;
use RomainMillan\WebPushNotification\Infrastructure\Network\DnsResolver;
use RomainMillan\WebPushNotification\Infrastructure\Network\PublicIpPolicy;
use RomainMillan\WebPushNotification\Infrastructure\Network\ResolvedHostPinning;
use RomainMillan\WebPushNotification\Tests\Support\TransportHarness;

final class SsrfTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function nonPublicAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10/8' => ['10.1.2.3'],
            'private 192.168/16' => ['192.168.1.1'],
            'link-local / cloud metadata' => ['169.254.169.254'],
            'carrier-grade NAT 100.64/10' => ['100.64.0.1'],
            'IPv4-mapped metadata' => ['::ffff:169.254.169.254'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unique local fd00::/8' => ['fd12:3456::1'],
            'unspecified' => ['0.0.0.0'],
        ];
    }

    #[DataProvider('nonPublicAddresses')]
    #[Test]
    public function it_should_refuse_non_public_addresses(string $ip): void
    {
        self::assertFalse((new PublicIpPolicy())->isPublic($ip));
    }

    #[Test]
    public function it_should_accept_public_addresses(): void
    {
        self::assertTrue((new PublicIpPolicy())->isPublic('142.250.74.10'));
        self::assertTrue((new PublicIpPolicy())->isPublic('2a00:1450:4007:80e::200a'));
    }

    #[Test]
    public function it_should_pin_the_checked_address_and_bracket_ipv6(): void
    {
        $plan = (new ResolvedHostPinning($this->resolver(['fcm.googleapis.com' => ['142.250.74.10'], 'web.push.apple.com' => ['2a00:1450:4007:80e::200a']]), new PublicIpPolicy()))
            ->planFor(['fcm.googleapis.com', 'web.push.apple.com', 'fcm.googleapis.com']);

        self::assertSame(['fcm.googleapis.com:443:142.250.74.10', 'web.push.apple.com:443:[2a00:1450:4007:80e::200a]'], $plan->resolveEntries());
    }

    #[Test]
    public function it_should_consider_a_host_unsafe_when_one_resolved_address_is_private(): void
    {
        $plan = (new ResolvedHostPinning($this->resolver(['fcm.googleapis.com' => ['142.250.74.10', '10.0.0.5']]), new PublicIpPolicy()))->planFor(['fcm.googleapis.com']);

        self::assertTrue($plan->isUnsafe('fcm.googleapis.com'));
        self::assertSame([], $plan->resolveEntries());
    }

    #[Test]
    public function it_should_consider_an_unresolvable_host_unsafe(): void
    {
        self::assertTrue((new ResolvedHostPinning($this->resolver([]), new PublicIpPolicy()))->planFor(['fcm.googleapis.com'])->isUnsafe('fcm.googleapis.com'));
    }

    #[Test]
    public function it_should_use_curl_so_the_pins_cannot_be_silently_ignored(): void
    {
        $options = WebPushClientFactory::createWithCurl(TransportHarness::vapid(), 15)->clientOptions(['fcm.googleapis.com:443:142.250.74.10']);

        self::assertInstanceOf(HandlerStack::class, $options['handler']);
        $handler = (new \ReflectionProperty(HandlerStack::class, 'handler'))->getValue($options['handler']);
        self::assertInstanceOf(CurlMultiHandler::class, $handler);
        self::assertFalse($options['allow_redirects']);
        self::assertTrue($options['verify']);
        self::assertIsArray($options['curl']);
        self::assertSame(['fcm.googleapis.com:443:142.250.74.10'], $options['curl'][\CURLOPT_RESOLVE]);
        self::assertSame(\CURLPROTO_HTTPS, $options['curl'][\CURLOPT_PROTOCOLS]);
    }

    /**
     * @param array<string, list<string>> $answers
     */
    private function resolver(array $answers): DnsResolver
    {
        return new class($answers) implements DnsResolver {
            /**
             * @param array<string, list<string>> $answers
             */
            public function __construct(private readonly array $answers)
            {
            }

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? [];
            }
        };
    }
}
