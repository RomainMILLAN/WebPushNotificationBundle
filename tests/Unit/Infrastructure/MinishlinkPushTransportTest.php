<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Infrastructure;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\Contract\EncodedPayload;
use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryStatus;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryTarget;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Testing\TestBrowser;
use RomainMillan\WebPushNotification\Tests\Support\TransportHarness;

final class MinishlinkPushTransportTest extends TestCase
{
    /**
     * @return array<string, array{Response, DeliveryStatus, FailureCategory}>
     */
    public static function answers(): array
    {
        return [
            'status 201' => [new Response(201), DeliveryStatus::Delivered, FailureCategory::None],
            'status 404' => [new Response(404), DeliveryStatus::Expired, FailureCategory::Gone],
            'status 410' => [new Response(410), DeliveryStatus::Expired, FailureCategory::Gone],
            'status 429' => [new Response(429, ['Retry-After' => '120']), DeliveryStatus::Transient, FailureCategory::RateLimited],
            'status 503' => [new Response(503), DeliveryStatus::Transient, FailureCategory::Server],
            'status 400' => [new Response(400), DeliveryStatus::Permanent, FailureCategory::Payload],
            'status 413' => [new Response(413), DeliveryStatus::Permanent, FailureCategory::Payload],
            '403 (VAPID)' => [new Response(403), DeliveryStatus::Permanent, FailureCategory::Vapid],
        ];
    }

    #[DataProvider('answers')]
    #[Test]
    public function it_should_every_answer_is_classified(Response $answer, DeliveryStatus $status, FailureCategory $category): void
    {
        $harness = new TransportHarness();
        $harness->responses->append($answer);
        $target = $this->target(TestBrowser::chrome());

        $outcome = $harness->transport->deliver([$target], $this->payload(), DeliveryOptions::createDefault())->outcomeFor($target->subscriptionId());

        self::assertSame($status, $outcome->status);
        self::assertSame($category, $outcome->category);
    }

    #[Test]
    public function it_should_retry_after_is_honoured(): void
    {
        $harness = new TransportHarness();
        $harness->responses->append(new Response(429, ['Retry-After' => '120']));
        $target = $this->target(TestBrowser::chrome());

        self::assertSame(120, $harness->transport->deliver([$target], $this->payload(), DeliveryOptions::createDefault())->retryAfterSeconds());
    }

    #[Test]
    public function it_should_a_network_failure_is_transient(): void
    {
        $harness = new TransportHarness();
        $harness->responses->append(new ConnectException('Connection refused for https://fcm.googleapis.com/fcm/send/secret', new Request('POST', 'https://fcm.googleapis.com/fcm/send/secret')));
        $target = $this->target(TestBrowser::chrome());

        $outcome = $harness->transport->deliver([$target], $this->payload(), DeliveryOptions::createDefault())->outcomeFor($target->subscriptionId());

        self::assertTrue($outcome->shouldRetry());
        self::assertSame(FailureCategory::Network, $outcome->category);
    }

    #[Test]
    public function it_should_the_payload_is_encrypted_and_the_rfc8030_headers_are_sent(): void
    {
        $harness = new TransportHarness();
        $harness->responses->append(new Response(201));

        $harness->transport->deliver([$this->target(TestBrowser::chrome())], $this->payload(), DeliveryOptions::createDefault()->withTtl(60)->withUrgency(Urgency::High)->withTopic('alert-1'));

        $request = $harness->sentRequest(0);
        self::assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        self::assertSame('60', $request->getHeaderLine('TTL'));
        self::assertSame('high', $request->getHeaderLine('Urgency'));
        self::assertSame('alert-1', $request->getHeaderLine('Topic'));
        self::assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('Hello', (string) $request->getBody());
    }

    #[Test]
    public function it_should_several_devices_are_mapped_back_to_their_own_outcome(): void
    {
        $harness = new TransportHarness();
        $harness->responses->append(new Response(201), new Response(410));
        $first = $this->target(TestBrowser::chrome('first'));
        $second = $this->target(TestBrowser::firefox('second'));

        $report = $harness->transport->deliver([$first, $second], $this->payload(), DeliveryOptions::createDefault());

        self::assertTrue($report->outcomeFor($first->subscriptionId())->isDelivered());
        self::assertTrue($report->outcomeFor($second->subscriptionId())->isExpired());
    }

    private function target(TestBrowser $browser): DeliveryTarget
    {
        return new DeliveryTarget(SubscriptionId::fromString(bin2hex(random_bytes(16))), $browser->address());
    }

    private function payload(): EncodedPayload
    {
        return (new PayloadEncoder())->encode(WebPushMessage::createWithTitle('Hello'));
    }
}
