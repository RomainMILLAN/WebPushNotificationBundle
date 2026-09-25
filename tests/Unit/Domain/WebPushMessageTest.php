<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\Action\DismissAction;
use RomainMillan\WebPushNotification\Domain\Message\ActionUrl;
use RomainMillan\WebPushNotification\Domain\Message\AssetUrl;
use RomainMillan\WebPushNotification\Domain\Message\ClickPath;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\MessageData;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use RomainMillan\WebPushNotification\Domain\Message\Tag;
use RomainMillan\WebPushNotification\Domain\Message\Urgency;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

final class WebPushMessageTest extends TestCase
{
    #[Test]
    public function it_should_the_default_tag_is_the_message_id_so_retries_replace_instead_of_stacking(): void
    {
        $message = WebPushMessage::createWithTitle('Hello');

        self::assertSame('wp-'.$message->id(), $message->toPayload()['tag']);
    }

    #[Test]
    public function it_should_insistent_requires_an_explicit_tag(): void
    {
        $this->expectException(InvalidValue::class);

        WebPushMessage::createWithTitle('Alert')->insistent();
    }

    #[Test]
    public function it_should_insistent_sets_require_interaction_and_renotify(): void
    {
        $payload = WebPushMessage::createWithTitle('Alert')->withTag(Tag::fromString('alert-42'))->insistent()->toPayload();

        self::assertTrue($payload['requireInteraction']);
        self::assertTrue($payload['renotify']);
        self::assertFalse($payload['silent']);
    }

    #[Test]
    public function it_should_at_most_two_actions(): void
    {
        $message = WebPushMessage::createWithTitle('Hi')
            ->withAction(new DismissAction(ActionLabel::fromActionAndTitle('one', 'One')))
            ->withAction(new DismissAction(ActionLabel::fromActionAndTitle('two', 'Two')));

        $this->expectException(InvalidValue::class);

        $message->withAction(new DismissAction(ActionLabel::fromActionAndTitle('three', 'Three')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function offOriginClickPaths(): array
    {
        return [
            'absolute URL' => ['https://evil.example/x'],
            'scheme-relative' => ['//evil.example/x'],
            'backslash' => ['/\\evil.example'],
            'javascript' => ['javascript:alert(1)'],
            'control character' => ["/app\n/x"],
            'empty' => [''],
        ];
    }

    #[DataProvider('offOriginClickPaths')]
    #[Test]
    public function it_should_a_click_path_never_leaves_the_origin(string $path): void
    {
        $this->expectException(InvalidValue::class);

        ClickPath::fromString($path);
    }

    #[Test]
    public function it_should_an_action_url_belongs_to_the_application_origin(): void
    {
        $origin = Origin::fromString('https://app.example.com');

        self::assertSame('https://app.example.com/alerts/1/ack?signature=x', ActionUrl::fromString('/alerts/1/ack?signature=x', $origin)->toString());
        self::assertSame('https://app.example.com/ack', ActionUrl::fromString('https://app.example.com/ack', $origin)->toString());

        $this->expectException(InvalidValue::class);
        ActionUrl::fromString('https://app.example.com.evil.example/ack', $origin);
    }

    #[Test]
    public function it_should_an_asset_is_an_in_origin_path_or_an_https_url(): void
    {
        self::assertSame('/icon.png', AssetUrl::fromString('/icon.png')->toString());

        $this->expectException(InvalidValue::class);
        AssetUrl::fromString('http://tracker.example/pixel.gif');
    }

    #[Test]
    public function it_should_data_is_flat_and_scalar(): void
    {
        self::assertSame(['id' => 42, 'kind' => 'payment'], MessageData::fromEntries(['id' => 42, 'kind' => 'payment'])->toPayload());

        $this->expectException(InvalidValue::class);
        MessageData::fromEntries(['nested' => ['no' => 'way']]);
    }

    #[Test]
    public function it_should_the_title_is_mandatory(): void
    {
        $this->expectException(InvalidValue::class);

        WebPushMessage::createWithTitle('   ');
    }

    #[Test]
    public function it_should_delivery_options_map_to_rfc8030_headers(): void
    {
        $options = DeliveryOptions::createDefault()->withTtl(60)->withUrgency(Urgency::High)->withTopic('alert-42');

        self::assertSame(['TTL' => 60, 'urgency' => 'high', 'topic' => 'alert-42'], $options->toTransportOptions());
        self::assertEquals($options, DeliveryOptions::fromArray($options->toArray()));
    }

    #[Test]
    public function it_should_a_topic_cannot_inject_headers(): void
    {
        $this->expectException(InvalidValue::class);

        DeliveryOptions::createDefault()->withTopic("x\r\nAuthorization: evil");
    }
}
