<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Vapid;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\b;
use function Symfony\Component\String\u;

/**
 * The application server's identity (RFC 8292). Validated at boot: a malformed key
 * fails loudly at deployment instead of answering 403 on every send in production.
 */
final readonly class VapidCredentials
{
    private function __construct(
        private string $publicKey,
        #[\SensitiveParameter]
        private string $privateKey,
        private string $subject,
    ) {
    }

    public static function fromKeys(string $publicKey, #[\SensitiveParameter] string $privateKey, string $subject): self
    {
        $public = self::decodeBase64Url($publicKey);

        // Uncompressed P-256 point: 0x04 || X || Y.
        if (65 !== b($public)->length() || "\x04" !== b($public)->slice(0, 1)->toString()) {
            throw InvalidValue::because('Cannot accept a VAPID public key that is not a base64url uncompressed P-256 point (65 bytes).');
        }

        if (32 !== b(self::decodeBase64Url($privateKey))->length()) {
            throw InvalidValue::because('Cannot accept a VAPID private key that is not base64url of 32 bytes.');
        }

        $isMailto = u($subject)->startsWith('mailto:') && u($subject)->length() > 7;
        $isHttps = u($subject)->startsWith('https://') && false !== filter_var($subject, \FILTER_VALIDATE_URL);

        if (!$isMailto && !$isHttps) {
            throw InvalidValue::because('Cannot accept a VAPID subject that is neither a mailto: nor an https: URL.');
        }

        return new self($publicKey, $privateKey, $subject);
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * @internal transport boundary
     *
     * @return array{VAPID: array{subject: string, publicKey: string, privateKey: string}}
     */
    public function toWebPushAuth(): array
    {
        return ['VAPID' => ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey]];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['publicKey' => $this->publicKey, 'privateKey' => '[redacted]', 'subject' => $this->subject];
    }

    private static function decodeBase64Url(string $value): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) {
            return '';
        }

        $decoded = base64_decode(u($value)->replace('-', '+')->replace('_', '/')->toString(), true);

        return false !== $decoded ? $decoded : '';
    }
}
