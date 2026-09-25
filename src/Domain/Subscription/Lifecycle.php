<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Exception\InvariantViolated;

/**
 * Registration history + status (Active | Retired).
 *
 * The registration dates survive retirement: a retired device still has a birth date
 * and is still listed ("expired device").
 */
final readonly class Lifecycle
{
    /**
     * @param list<Retirement> $retirement empty while active, one element once retired
     */
    private function __construct(
        private \DateTimeImmutable $registeredAt,
        private \DateTimeImmutable $lastRegisteredAt,
        private array $retirement,
    ) {
    }

    public static function createRegisteredAt(\DateTimeImmutable $at): self
    {
        return new self($at, $at, []);
    }

    /**
     * @internal persistence only
     */
    public static function reconstituteActive(\DateTimeImmutable $registeredAt, \DateTimeImmutable $lastRegisteredAt): self
    {
        return new self($registeredAt, $lastRegisteredAt, []);
    }

    /**
     * @internal persistence only
     */
    public static function reconstituteRetired(\DateTimeImmutable $registeredAt, \DateTimeImmutable $lastRegisteredAt, Retirement $retirement): self
    {
        return new self($registeredAt, $lastRegisteredAt, [$retirement]);
    }

    public function isActive(): bool
    {
        return [] === $this->retirement;
    }

    public function renewedAt(\DateTimeImmutable $at): self
    {
        if (!$this->isActive()) {
            throw InvariantViolated::notActive();
        }

        return new self($this->registeredAt, $at, []);
    }

    public function reactivatedAt(\DateTimeImmutable $at): self
    {
        if ($this->isActive()) {
            throw InvariantViolated::alreadyActive();
        }

        return new self($this->registeredAt, $at, []);
    }

    public function retiredAt(RetirementReason $reason, \DateTimeImmutable $at): self
    {
        if (!$this->isActive()) {
            throw InvariantViolated::notActive();
        }

        return new self($this->registeredAt, $this->lastRegisteredAt, [new Retirement($reason, $at)]);
    }

    public function isRegisteredBefore(self $other): bool
    {
        return $this->lastRegisteredAt < $other->lastRegisteredAt;
    }

    public function isRetiredSince(\DateTimeImmutable $cutoff): bool
    {
        foreach ($this->retirement as $retirement) {
            return $retirement->isBefore($cutoff);
        }

        return false;
    }

    /**
     * @internal persistence Memento
     *
     * @return array{registered_at: \DateTimeImmutable, last_registered_at: \DateTimeImmutable, retired_at: ?\DateTimeImmutable, retirement_reason: ?string}
     */
    public function snapshot(): array
    {
        $snapshot = ['registered_at' => $this->registeredAt, 'last_registered_at' => $this->lastRegisteredAt, 'retired_at' => null, 'retirement_reason' => null];

        foreach ($this->retirement as $retirement) {
            $snapshot['retired_at'] = $retirement->at;
            $snapshot['retirement_reason'] = $retirement->reason->value;
        }

        return $snapshot;
    }
}
