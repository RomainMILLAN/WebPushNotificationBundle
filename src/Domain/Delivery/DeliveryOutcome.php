<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

/**
 * The classified result of one delivery attempt.
 *
 * Carries a status code and a category — NEVER the push service's reason text nor an
 * exception message: those embed the request URL, i.e. the capability endpoint, and
 * outcomes end up in logs and failure transports.
 */
final readonly class DeliveryOutcome
{
    private function __construct(
        public DeliveryStatus $status,
        public FailureCategory $category,
        public int $httpStatus,
        public int $retryAfterSeconds,
    ) {
    }

    public static function delivered(int $httpStatus = 201): self
    {
        return new self(DeliveryStatus::Delivered, FailureCategory::None, $httpStatus, 0);
    }

    public static function expired(int $httpStatus): self
    {
        return new self(DeliveryStatus::Expired, FailureCategory::Gone, $httpStatus, 0);
    }

    public static function transient(FailureCategory $category, int $httpStatus = 0, int $retryAfterSeconds = 0): self
    {
        return new self(DeliveryStatus::Transient, $category, $httpStatus, max(0, $retryAfterSeconds));
    }

    public static function permanent(FailureCategory $category, int $httpStatus = 0): self
    {
        return new self(DeliveryStatus::Permanent, $category, $httpStatus, 0);
    }

    /** Refused by the core before reaching the network (retired, owner changed, host no longer allowed). */
    public static function skipped(FailureCategory $category): self
    {
        return new self(DeliveryStatus::Skipped, $category, 0, 0);
    }

    public function isDelivered(): bool
    {
        return DeliveryStatus::Delivered === $this->status;
    }

    public function isExpired(): bool
    {
        return DeliveryStatus::Expired === $this->status;
    }

    public function shouldRetry(): bool
    {
        return DeliveryStatus::Transient === $this->status;
    }
}
