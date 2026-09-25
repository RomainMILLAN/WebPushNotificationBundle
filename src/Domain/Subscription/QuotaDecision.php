<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

final readonly class QuotaDecision
{
    /**
     * @param list<Subscription> $evictions
     */
    private function __construct(
        private bool $refused,
        private array $evictions,
    ) {
    }

    /**
     * @param list<Subscription> $evictions
     */
    public static function evict(array $evictions): self
    {
        return new self(false, $evictions);
    }

    public static function refuse(): self
    {
        return new self(true, []);
    }

    public function isRefused(): bool
    {
        return $this->refused;
    }

    /**
     * @return list<Subscription>
     */
    public function evictions(): array
    {
        return $this->evictions;
    }
}
