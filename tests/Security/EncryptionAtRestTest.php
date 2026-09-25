<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriberId;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Infrastructure\ClientState\HmacClientStateMarkerFactory;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\AeadSubscriptionCipher;
use RomainMillan\WebPushNotification\Infrastructure\Crypto\EncryptionKey;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidCredentials;
use RomainMillan\WebPushNotification\Testing\TestBrowser;

final class EncryptionAtRestTest extends TestCase
{
    /** @var array<string, string> */
    private static array $keys = [];

    #[Test]
    public function it_should_encrypt_secrets_in_the_row_and_restore_them(): void
    {
        $mapper = new SubscriptionRowMapper(new AeadSubscriptionCipher($this->key('k1')));
        $browser = TestBrowser::chrome('secret-token');
        $subscription = Subscription::register(SubscriptionId::fromString(str_repeat('a', 32)), IdentifiedOwner::fromSubscriberId('user:1'), $browser->address(), new \DateTimeImmutable());

        $row = $mapper->toRow($subscription);

        self::assertStringStartsWith('v1:k1:', $row['endpoint']);
        self::assertStringNotContainsString('secret-token', json_encode($row, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($browser->auth(), $row['auth']);
        self::assertTrue($mapper->fromRow($row)->isProvenBy($browser->auth()));
    }

    #[Test]
    public function it_should_refuse_to_decrypt_a_ciphertext_moved_to_another_row(): void
    {
        $mapper = new SubscriptionRowMapper(new AeadSubscriptionCipher($this->key('k1')));
        $victim = $mapper->toRow(Subscription::register(SubscriptionId::fromString(str_repeat('a', 32)), IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome('victim')->address(), new \DateTimeImmutable()));
        $attacker = $mapper->toRow(Subscription::register(SubscriptionId::fromString(str_repeat('b', 32)), IdentifiedOwner::fromSubscriberId('user:2'), TestBrowser::chrome('attacker')->address(), new \DateTimeImmutable()));

        $attacker['auth'] = $victim['auth'];

        $this->expectException(InvalidValue::class);
        $mapper->fromRow($attacker);
    }

    #[Test]
    public function it_should_rotate_keys_without_rewriting_existing_rows(): void
    {
        $oldMapper = new SubscriptionRowMapper(new AeadSubscriptionCipher($this->key('old')));
        $row = $oldMapper->toRow(Subscription::register(SubscriptionId::fromString(str_repeat('a', 32)), IdentifiedOwner::fromSubscriberId('user:1'), TestBrowser::chrome()->address(), new \DateTimeImmutable()));

        $rotated = new SubscriptionRowMapper(new AeadSubscriptionCipher($this->key('new'), [$this->key('old')]));

        self::assertStringStartsWith('v1:new:', $rotated->toRow($rotated->fromRow($row))['endpoint']);
    }

    #[Test]
    public function it_should_refuse_a_key_that_does_not_decode_to_32_bytes(): void
    {
        $this->expectException(InvalidValue::class);

        EncryptionKey::fromConfiguration('k1:'.base64_encode('too short'));
    }

    #[Test]
    public function it_should_validate_vapid_keys_at_boot(): void
    {
        $this->expectException(InvalidValue::class);

        VapidCredentials::fromKeys('not-a-key', 'not-a-key', 'mailto:ops@example.com');
    }

    #[Test]
    public function it_should_derive_a_stable_client_state_marker_per_subscriber_from_the_secret(): void
    {
        $factory = new HmacClientStateMarkerFactory('secret-one');

        self::assertTrue($factory->createForSubscriber(SubscriberId::fromString('user:1'))->equals($factory->createForSubscriber(SubscriberId::fromString('user:1'))));
        self::assertFalse($factory->createForSubscriber(SubscriberId::fromString('user:1'))->equals($factory->createForSubscriber(SubscriberId::fromString('user:2'))));
        self::assertFalse($factory->createForSubscriber(SubscriberId::fromString('user:1'))->equals((new HmacClientStateMarkerFactory('secret-two'))->createForSubscriber(SubscriberId::fromString('user:1'))));
    }

    private function key(string $id): EncryptionKey
    {
        self::$keys[$id] ??= base64_encode(random_bytes(32));

        return EncryptionKey::fromConfiguration($id.':'.self::$keys[$id]);
    }
}
