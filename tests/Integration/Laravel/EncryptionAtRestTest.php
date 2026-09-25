<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent\SubscriptionRecord;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Testing\TestBrowser;

#[DefineEnvironment('encryptAtRest')]
final class EncryptionAtRestTest extends LaravelTestCase
{
    #[Test]
    public function it_should_store_neither_the_endpoint_nor_the_auth_secret_in_plaintext(): void
    {
        $browser = TestBrowser::chrome('capability-token');

        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);

        $row = json_encode($this->app()->make(DatabaseManager::class)->connection()->table(SubscriptionRecord::TABLE)->first(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('capability-token', $row);
        self::assertStringNotContainsString($browser->auth(), $row);
        self::assertStringContainsString('v1:k1:', $row);
    }

    #[Test]
    public function it_should_restore_an_encrypted_subscription_with_its_keys(): void
    {
        $browser = TestBrowser::chrome();
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), $browser);

        $restored = $this->repository()->getByFingerprint($browser->address()->fingerprint());

        self::assertTrue($restored->isProvenBy($browser->auth()));
    }

    #[Test]
    public function it_should_hide_the_secrets_when_the_record_is_serialized(): void
    {
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), TestBrowser::chrome());

        $record = SubscriptionRecord::query()->firstOrFail();

        self::assertSame([], array_intersect(['endpoint', 'p256dh', 'auth'], array_keys($record->toArray())));
        self::assertStringNotContainsString('v1:k1:', $record->toJson());
    }

    protected function encryptAtRest(Application $app): void
    {
        self::configure($app, ['web-push.encryption.current' => 'k1:'.base64_encode(str_repeat('e', 32))]);
    }
}
