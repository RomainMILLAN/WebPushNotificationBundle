<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

final readonly class Retirement
{
    public function __construct(
        public RetirementReason $reason,
        public \DateTimeImmutable $at,
    ) {
    }

    public function isBefore(\DateTimeImmutable $cutoff): bool
    {
        return $this->at < $cutoff;
    }
}
