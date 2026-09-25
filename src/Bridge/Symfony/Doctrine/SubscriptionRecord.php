<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine;

/**
 * Persistence-only entity, mapped in config/doctrine/SubscriptionRecord.orm.xml so
 * that doctrine:schema:* and doctrine:migrations:diff create the table.
 *
 * The adapter reads and writes rows through DBAL and the shared SubscriptionRowMapper:
 * this class is never hydrated by the package, never serialized, and carries no
 * behaviour. Its properties only exist because Doctrine's metadata requires them.
 *
 * Not final: Doctrine generates lazy proxies for every mapped entity (cache warmer).
 *
 * @internal
 */
class SubscriptionRecord
{
    // @phpstan-ignore-next-line property.unused (schema only)
    private string $id;
    // @phpstan-ignore-next-line property.unused
    private string $ownerType;
    // @phpstan-ignore-next-line property.unused
    private ?string $subscriberId;
    // @phpstan-ignore-next-line property.unused
    private string $endpoint;
    // @phpstan-ignore-next-line property.unused
    private string $endpointHash;
    // @phpstan-ignore-next-line property.unused
    private string $pushHost;
    // @phpstan-ignore-next-line property.unused
    private string $p256dh;
    // @phpstan-ignore-next-line property.unused
    private string $auth;
    // @phpstan-ignore-next-line property.unused
    private string $contentEncoding;
    // @phpstan-ignore-next-line property.unused
    private \DateTimeImmutable $registeredAt;
    // @phpstan-ignore-next-line property.unused
    private \DateTimeImmutable $lastRegisteredAt;
    // @phpstan-ignore-next-line property.unused
    private ?\DateTimeImmutable $retiredAt;
    // @phpstan-ignore-next-line property.unused
    private ?string $retirementReason;

    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['record' => '[redacted]'];
    }
}
