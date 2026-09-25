<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Domain\ClientState\ClientStateMarker;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryStatus;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Message\Action\ActionLabel;
use RomainMillan\WebPushNotification\Domain\Message\MessageData;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use RomainMillan\WebPushNotification\Domain\Message\Tag;
use RomainMillan\WebPushNotification\Domain\Subscription\ClaimOutcome;
use RomainMillan\WebPushNotification\Domain\Subscription\EndpointFingerprint;
use RomainMillan\WebPushNotification\Domain\Subscription\LockKey;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

final class ValueObjectsTest extends TestCase
{
    #[Test]
    public function it_should_normalise_an_origin_and_keep_its_port(): void
    {
        self::assertSame('https://app.example.com', Origin::fromString('https://App.Example.com/')->toString());
        self::assertSame('https://app.example.com:8443', Origin::fromString('https://app.example.com:8443')->toString());
        self::assertSame('http://localhost:8000', Origin::fromString('http://localhost:8000')->toString());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidOrigins(): array
    {
        return [
            'plain http' => ['http://app.example.com'],
            'with a path' => ['https://app.example.com/app'],
            'with a query' => ['https://app.example.com?x=1'],
            'with a fragment' => ['https://app.example.com#x'],
            'with credentials' => ['https://user:pass@app.example.com'],
            'not an URL' => ['app.example.com'],
            'other scheme' => ['ftp://app.example.com'],
        ];
    }

    #[DataProvider('invalidOrigins')]
    #[Test]
    public function it_should_refuse_anything_but_a_bare_origin(string $origin): void
    {
        $this->expectException(InvalidValue::class);

        Origin::fromString($origin);
    }

    #[Test]
    public function it_should_recognise_urls_of_its_own_origin_only(): void
    {
        $origin = Origin::fromString('https://app.example.com');

        self::assertTrue($origin->isOriginOf('https://app.example.com'));
        self::assertTrue($origin->isOriginOf('https://app.example.com/ack'));
        self::assertTrue($origin->isOriginOf('https://app.example.com?x=1'));
        self::assertFalse($origin->isOriginOf('https://app.example.com.evil.example/ack'));
        self::assertFalse($origin->isOriginOf('https://app.example.community/ack'));
        self::assertFalse($origin->isOriginOf('http://app.example.com/ack'));
    }

    #[Test]
    public function it_should_order_locks_endpoint_first_then_owners_sorted_without_duplicates(): void
    {
        $endpoint = LockKey::createForEndpoint(EndpointFingerprint::fromCanonicalEndpoint('https://fcm.googleapis.com/fcm/send/x'));
        $alice = LockKey::createForSubscriber(SubscriberId::fromString('user:alice'));
        $bob = LockKey::createForSubscriber(SubscriberId::fromString('user:bob'));

        $ordered = array_map(static fn (LockKey $key): string => $key->toString(), LockKey::inAcquisitionOrder([$bob, $alice, $endpoint, $alice]));

        self::assertCount(3, $ordered);
        self::assertSame($endpoint->toString(), $ordered[0]);
        self::assertSame([$ordered[1], $ordered[2]], [min($alice->toString(), $bob->toString()), max($alice->toString(), $bob->toString())]);
    }

    #[Test]
    public function it_should_never_put_the_subscriber_identity_in_a_lock_key(): void
    {
        $key = LockKey::createForSubscriber(SubscriberId::fromString('user:alice@example.com'))->toString();

        self::assertStringStartsWith('webpush:owner:', $key);
        self::assertStringNotContainsString('alice', $key);
        self::assertSame('webpush:owner:'.hash('sha256', 'user:alice@example.com'), $key);
    }

    #[Test]
    public function it_should_carry_status_category_code_and_retry_delay(): void
    {
        $transient = DeliveryOutcome::transient(FailureCategory::RateLimited, 429, 30);

        self::assertSame([DeliveryStatus::Transient, FailureCategory::RateLimited, 429, 30], [$transient->status, $transient->category, $transient->httpStatus, $transient->retryAfterSeconds]);
        self::assertTrue($transient->shouldRetry());
        self::assertFalse($transient->isDelivered());
        self::assertFalse($transient->isExpired());
        self::assertSame(0, DeliveryOutcome::transient(FailureCategory::Network, 0, -5)->retryAfterSeconds);

        $expired = DeliveryOutcome::expired(410);
        self::assertSame([DeliveryStatus::Expired, FailureCategory::Gone, 410], [$expired->status, $expired->category, $expired->httpStatus]);
        self::assertTrue($expired->isExpired());
        self::assertFalse($expired->shouldRetry());

        $delivered = DeliveryOutcome::delivered();
        self::assertSame([DeliveryStatus::Delivered, FailureCategory::None, 201], [$delivered->status, $delivered->category, $delivered->httpStatus]);
        self::assertTrue($delivered->isDelivered());

        $permanent = DeliveryOutcome::permanent(FailureCategory::Vapid, 403);
        self::assertSame([DeliveryStatus::Permanent, FailureCategory::Vapid, 403, 0], [$permanent->status, $permanent->category, $permanent->httpStatus, $permanent->retryAfterSeconds]);
        self::assertFalse($permanent->shouldRetry());

        $skipped = DeliveryOutcome::skipped(FailureCategory::OwnerChanged);
        self::assertSame([DeliveryStatus::Skipped, FailureCategory::OwnerChanged, 0], [$skipped->status, $skipped->category, $skipped->httpStatus]);
    }

    #[Test]
    public function it_should_tell_refusals_and_quota_relevant_outcomes_apart(): void
    {
        $refused = array_values(array_filter(ClaimOutcome::cases(), static fn (ClaimOutcome $outcome): bool => $outcome->isRefused()));
        $quota = array_values(array_filter(ClaimOutcome::cases(), static fn (ClaimOutcome $outcome): bool => $outcome->requiresQuota()));

        self::assertSame([ClaimOutcome::RefusedHostNotAllowed, ClaimOutcome::RefusedAuthMismatch, ClaimOutcome::RefusedDowngrade, ClaimOutcome::RefusedAnonymousCap], $refused);
        self::assertSame([ClaimOutcome::Registered, ClaimOutcome::Reassigned, ClaimOutcome::Reactivated], $quota);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function tags(): array
    {
        return [
            'simple' => ['alert-42', true],
            'with colon and dot' => ['payment:42.v2', true],
            '64 characters' => [str_repeat('a', 64), true],
            '65 characters' => [str_repeat('a', 65), false],
            'empty' => ['', false],
            'space' => ['alert 42', false],
            'slash' => ['alert/42', false],
            'trailing newline' => ["alert\n", false],
        ];
    }

    #[DataProvider('tags')]
    #[Test]
    public function it_should_validate_tags(string $tag, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidValue::class);
        }

        $created = Tag::fromString($tag);

        self::assertSame($tag, $created->toString());
        self::assertTrue($created->isExplicit());
    }

    #[Test]
    public function it_should_derive_an_implicit_tag_from_the_message_id(): void
    {
        $tag = Tag::createDefaultForMessage('0123456789abcdef');

        self::assertSame('wp-0123456789abcdef', $tag->toString());
        self::assertFalse($tag->isExplicit());
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function actionLabels(): array
    {
        return [
            'valid' => ['ack', 'Acknowledge', true],
            'digits and dash' => ['open-2', 'Open', true],
            '32 characters' => ['a'.str_repeat('b', 31), 'Ok', true],
            '33 characters' => ['a'.str_repeat('b', 32), 'Ok', false],
            'starts with a digit' => ['2open', 'Open', false],
            'uppercase' => ['Open', 'Open', false],
            'blank title' => ['ack', '   ', false],
            '40 characters title' => ['ack', str_repeat('x', 40), true],
            '41 characters title' => ['ack', str_repeat('x', 41), false],
            'control character in title' => ['ack', "Ack\x07", false],
        ];
    }

    #[DataProvider('actionLabels')]
    #[Test]
    public function it_should_validate_action_labels(string $action, string $title, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidValue::class);
        }

        self::assertSame(['action' => $action, 'title' => $title], ActionLabel::fromActionAndTitle($action, $title)->toPayload());
    }

    #[Test]
    public function it_should_bound_message_data(): void
    {
        $sixteen = [];
        for ($i = 0; $i < 16; ++$i) {
            $sixteen['key'.$i] = $i;
        }

        self::assertCount(16, MessageData::fromEntries($sixteen)->toPayload());
        self::assertSame([], MessageData::empty()->toPayload());
        self::assertSame(['s' => str_repeat('x', 256)], MessageData::fromEntries(['s' => str_repeat('x', 256)])->toPayload());

        foreach ([$sixteen + ['key16' => 16], ['s' => str_repeat('x', 257)], ['1key' => 1], [0 => 'numeric key'], ['k' => null]] as $invalid) {
            try {
                MessageData::fromEntries($invalid);
                self::fail('Invalid data should be refused.');
            } catch (InvalidValue) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_should_validate_identifiers(): void
    {
        self::assertSame(str_repeat('a', 32), SubscriptionId::fromString(str_repeat('a', 32))->toString());
        self::assertTrue(SubscriptionId::fromString(str_repeat('a', 32))->equals(SubscriptionId::fromString(str_repeat('a', 32))));
        self::assertFalse(SubscriptionId::fromString(str_repeat('a', 32))->equals(SubscriptionId::fromString(str_repeat('b', 32))));
        self::assertSame(str_repeat('x', 191), SubscriberId::fromString(str_repeat('x', 191))->toString());
        self::assertTrue(ClientStateMarker::fromDigest('0123456789abcdef')->equals(ClientStateMarker::fromDigest('0123456789abcdef')));
        self::assertSame('0123456789abcdef', ClientStateMarker::fromDigest('0123456789abcdef')->toString());

        foreach ([
            static fn (): SubscriptionId => SubscriptionId::fromString(str_repeat('A', 32)),
            static fn (): SubscriptionId => SubscriptionId::fromString(str_repeat('a', 31)),
            static fn (): SubscriberId => SubscriberId::fromString(''),
            static fn (): SubscriberId => SubscriberId::fromString(str_repeat('x', 192)),
            static fn (): SubscriberId => SubscriberId::fromString('user 1'),
            static fn (): ClientStateMarker => ClientStateMarker::fromDigest('0123456789ABCDEF'),
            static fn (): EndpointFingerprint => EndpointFingerprint::reconstitute(str_repeat('a', 63)),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('An invalid identifier should be refused.');
            } catch (InvalidValue) {
                self::addToAssertionCount(1);
            }
        }
    }
}
