<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Security;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryTarget;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Infrastructure\Network\DnsResolver;
use RomainMillan\WebPushNotification\Infrastructure\Network\PublicIpPolicy;
use RomainMillan\WebPushNotification\Infrastructure\Network\ResolvedHostPinning;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Support\TransportHarness;

/**
 * The endpoint is a capability URL: whoever holds it can push to the device. It must
 * never reach a log, an exception message or a report — whatever the push service or
 * Guzzle put in their reason texts.
 */
final class CapabilityLeakTest extends TestCase
{
    private const TOKEN = 'capability-token-that-must-never-leak';

    #[Test]
    public function it_should_never_log_the_endpoint(): void
    {
        $harness = new TransportHarness();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.self::TOKEN;
        $request = new Request('POST', $endpoint);
        $harness->responses->append(
            new RequestException('Client error: `POST '.$endpoint.'` resulted in a `410 Gone`', $request, new Response(410, [], 'gone '.$endpoint)),
            new RequestException('Server error: `POST '.$endpoint.'` resulted in a `503`', $request, new Response(503)),
            new ConnectException('cURL error 7: Failed to connect to '.$endpoint, $request),
            new Response(403, [], 'invalid JWT for '.$endpoint),
        );
        $targets = array_map(
            static fn (int $i): DeliveryTarget => new DeliveryTarget(SubscriptionId::fromString(str_pad((string) $i, 32, '0', \STR_PAD_LEFT)), (new TestBrowser($endpoint.$i))->address()),
            [1, 2, 3, 4],
        );

        $report = $harness->transport->deliver($targets, (new PayloadEncoder())->encode(WebPushMessage::createWithTitle('Hi')), DeliveryOptions::createDefault());

        self::assertNotEmpty($harness->logger->records);
        self::assertStringNotContainsString(self::TOKEN, $harness->logger->dump());
        self::assertStringNotContainsString(self::TOKEN, print_r($report, true));
        self::assertStringNotContainsString(self::TOKEN, serialize($report));
    }

    #[Test]
    public function it_should_refuse_an_unsafe_host_without_leaking_the_endpoint(): void
    {
        $rebinding = new class implements DnsResolver {
            public function resolve(string $host): array
            {
                return ['142.250.74.10', '169.254.169.254'];
            }
        };
        $harness = new TransportHarness(new ResolvedHostPinning($rebinding, new PublicIpPolicy()));
        $target = new DeliveryTarget(SubscriptionId::fromString(str_repeat('a', 32)), TestBrowser::chrome(self::TOKEN)->address());

        $report = $harness->transport->deliver([$target], (new PayloadEncoder())->encode(WebPushMessage::createWithTitle('Hi')), DeliveryOptions::createDefault());

        self::assertSame(0, $harness->sentCount(), 'nothing may be sent to a host resolving to a private address');
        self::assertSame('unsafe', $report->outcomeFor($target->subscriptionId())->category->value);
        self::assertStringNotContainsString(self::TOKEN, $harness->logger->dump());
    }

    #[Test]
    public function it_should_never_dump_the_secrets_of_domain_objects(): void
    {
        $browser = TestBrowser::chrome(self::TOKEN);
        $target = new DeliveryTarget(SubscriptionId::fromString(str_repeat('b', 32)), $browser->address());

        foreach ([$browser->address(), $target, TransportHarness::vapid()] as $object) {
            $dump = print_r($object, true);
            self::assertStringNotContainsString(self::TOKEN, $dump);
            self::assertStringNotContainsString($browser->auth(), $dump);
        }

        self::assertStringContainsString('[redacted]', print_r(TransportHarness::vapid(), true));
    }
}
