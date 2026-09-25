<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Application\Contract\EncodedPayload;
use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Application\Exception\InvalidPayload;
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\Action\NavigateAction;
use RomainMillan\WebPushNotification\Domain\Message\Action\PostAction;
use RomainMillan\WebPushNotification\Domain\Message\ActionUrl;
use RomainMillan\WebPushNotification\Domain\Message\AssetUrl;
use RomainMillan\WebPushNotification\Domain\Message\ClickPath;
use RomainMillan\WebPushNotification\Domain\Message\MessageData;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use RomainMillan\WebPushNotification\Domain\Message\Tag;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

/**
 * The published language: the PHP side must produce exactly what the service worker
 * tests consume (tests/Fixtures/payload/*.json, also loaded by Vitest).
 */
final class PayloadContractTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/payload/';

    #[Test]
    public function it_should_encode_a_minimal_message_as_the_shared_fixture(): void
    {
        $encoded = (new PayloadEncoder())->encode(WebPushMessage::createWithTitle('Hello'));

        self::assertSame($this->fixture('minimal.json', $encoded->messageId(), '0123456789abcdef'), $encoded->toString());
    }

    #[Test]
    public function it_should_encode_a_full_message_as_the_shared_fixture(): void
    {
        $origin = Origin::fromString('https://app.example.com');
        $message = WebPushMessage::createWithTitle('Payment received', '120 € from ACME')
            ->withTag(Tag::fromString('payment-42'))
            ->insistent()
            ->withIcon(AssetUrl::fromString('/static/icon-192.png'))
            ->withBadge(AssetUrl::fromString('/static/badge-96.png'))
            ->withAction(new PostAction(ActionLabel::fromActionAndTitle('ack', 'Acknowledge'), ActionUrl::fromString('/alerts/42/ack?signature=abc', $origin)))
            ->withAction(new NavigateAction(ActionLabel::fromActionAndTitle('open', 'Open'), ClickPath::fromString('/app/payments/42')))
            ->withData(MessageData::fromEntries(['paymentId' => 42, 'currency' => 'EUR']))
            ->withClickPath(ClickPath::fromString('/app/payments/42'))
            ->withBadgeCount(3);

        $encoded = (new PayloadEncoder())->encode($message);

        self::assertSame($this->fixture('full.json', $encoded->messageId(), 'fedcba9876543210'), $encoded->toString());
    }

    #[Test]
    public function it_should_refuse_a_payload_larger_than_the_padding_target(): void
    {
        $this->expectException(InvalidPayload::class);

        (new PayloadEncoder())->encode(WebPushMessage::createWithTitle(str_repeat('é', 120), str_repeat('界', 1000)));
    }

    #[Test]
    public function it_should_restore_a_queued_payload_only_if_it_speaks_v1(): void
    {
        $encoded = (new PayloadEncoder())->encode(WebPushMessage::createWithTitle('Hello'));

        self::assertEquals($encoded, EncodedPayload::fromQueuedJson($encoded->toString()));

        $this->expectException(InvalidPayload::class);
        EncodedPayload::fromQueuedJson('{"v":2,"id":"x"}');
    }

    private function fixture(string $name, string $actualId, string $fixtureId): string
    {
        $json = file_get_contents(self::FIXTURES.$name);
        self::assertIsString($json);

        return str_replace($fixtureId, $actualId, trim($json));
    }
}
