<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\ReadModel;

final readonly class SubscriptionPeriod
{
    public function __construct(
        public \DateTimeImmutable $registeredAt,
        public \DateTimeImmutable $lastRegisteredAt,
        public string $status,
        public ?\DateTimeImmutable $retiredAt,
    ) {
    }

    public function isActive(): bool
    {
        return 'active' === $this->status;
    }
}
