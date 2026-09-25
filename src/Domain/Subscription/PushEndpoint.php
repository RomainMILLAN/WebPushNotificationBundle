<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidPushEndpoint;

use function Symfony\Component\String\u;

/**
 * The URL a push service gave the browser, and that THE SERVER will call.
 *
 * This is an OUTBOUND surface: while it is a string, every consumer starts its
 * distrust from scratch; once it is a PushEndpoint, an invalid one is unconstructible.
 *
 * Shape only. Whether the host is an allowed push service is a Specification
 * (AllowedPushServices), checked at registration AND at delivery but never at
 * reconstitution — removing a host from the configuration must not make existing
 * rows unreadable or unpurgeable.
 *
 * The order of the checks IS the check: length before syntax (never parse an unbounded
 * input), syntax before semantics.
 */
final readonly class PushEndpoint
{
    public const MAX_LENGTH = 512;

    private function __construct(
        #[\SensitiveParameter]
        private string $url,
        private string $host,
    ) {
    }

    public static function fromString(#[\SensitiveParameter] string $raw): self
    {
        if (u($raw)->length() > self::MAX_LENGTH) {
            throw InvalidPushEndpoint::tooLong(self::MAX_LENGTH);
        }

        // Rejected before any parsing: parse_url, filter_var and cURL do not agree on
        // how to read them, and a parser differential is how validation gets bypassed.
        if (1 === preg_match('/[\s\x00-\x1F\x7F\\\\]/', $raw)) {
            throw InvalidPushEndpoint::carryingForbiddenCharacters();
        }

        if (false === filter_var($raw, \FILTER_VALIDATE_URL)) {
            throw InvalidPushEndpoint::notAnUrl();
        }

        $parts = parse_url($raw);

        if (!\is_array($parts) || 'https' !== ($parts['scheme'] ?? null)) {
            throw InvalidPushEndpoint::notHttps();
        }

        // FILTER_VALIDATE_URL accepts https://fcm.googleapis.com@evil.example/: the
        // real host is then evil.example.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw InvalidPushEndpoint::carryingUserInfo();
        }

        if (isset($parts['port'])) {
            throw InvalidPushEndpoint::carryingExplicitPort();
        }

        $host = $parts['host'] ?? '';

        // A push service has a name, not an address.
        if ('' === $host || u($host)->startsWith('[') || false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            throw InvalidPushEndpoint::hostIsAnIpLiteral();
        }

        // Rebuilt to be COMPARED, never used as a rewrite: a silently "fixed" endpoint
        // would be unknown to the push service, answer 404, and be retired as expired.
        $canonical = 'https://'.u($host)->lower()->toString()
            .($parts['path'] ?? '')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');

        if ($canonical !== $raw) {
            throw InvalidPushEndpoint::notCanonical();
        }

        return new self($raw, u($host)->lower()->toString());
    }

    public function fingerprint(): EndpointFingerprint
    {
        return EndpointFingerprint::fromCanonicalEndpoint($this->url);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->url, $other->url);
    }

    /**
     * @internal persistence and transport boundary — never log it
     */
    public function toString(): string
    {
        return $this->url;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'fingerprint' => $this->fingerprint()->short()];
    }
}
