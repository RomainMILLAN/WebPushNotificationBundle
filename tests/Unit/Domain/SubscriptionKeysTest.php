<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionKeys;

final class SubscriptionKeysTest extends TestCase
{
    private const P256DH = 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM';
    private const AUTH = 'tBHItJI5svbpez7KI4CCXg';

    #[Test]
    public function it_should_accepts_browser_keys(): void
    {
        $keys = SubscriptionKeys::fromStrings(self::P256DH, self::AUTH);

        self::assertTrue($keys->authMatches(self::AUTH));
        self::assertFalse($keys->authMatches('AAAAAAAAAAAAAAAAAAAAAA'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'short p256dh' => ['BNcR', self::AUTH],
            'short auth' => [self::P256DH, 'tBHI'],
            'p256dh outside base64url' => [str_repeat('+', 87), self::AUTH],
            'auth outside base64url' => [self::P256DH, str_repeat('/', 22)],
        ];
    }

    #[DataProvider('invalidKeys')]
    #[Test]
    public function it_should_refuses_malformed_keys(string $p256dh, string $auth): void
    {
        $this->expectException(InvalidValue::class);

        SubscriptionKeys::fromStrings($p256dh, $auth);
    }

    #[Test]
    public function it_should_never_dumps_the_secrets(): void
    {
        $dump = print_r(SubscriptionKeys::fromStrings(self::P256DH, self::AUTH), true);

        self::assertStringNotContainsString(self::AUTH, $dump);
        self::assertStringNotContainsString(self::P256DH, $dump);
    }
}
