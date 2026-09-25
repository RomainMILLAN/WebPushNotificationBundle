<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * Memento of a Subscription for persistence mappers — the aggregate exposes no getters.
 *
 * @internal carries secrets (endpoint, keys): never log, serialize or dump it
 */
final readonly class SubscriptionSnapshot
{
    /**
     * @param array{endpoint: string, endpoint_hash: string, host: string, p256dh: string, auth: string, content_encoding: string}                          $address
     * @param array{registered_at: \DateTimeImmutable, last_registered_at: \DateTimeImmutable, retired_at: ?\DateTimeImmutable, retirement_reason: ?string} $lifecycle
     */
    public function __construct(
        public string $id,
        public ?string $subscriberId,
        #[\SensitiveParameter]
        public array $address,
        public array $lifecycle,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'endpoint_hash' => $this->address['endpoint_hash'], 'address' => '[redacted]', 'lifecycle' => $this->lifecycle];
    }
}
