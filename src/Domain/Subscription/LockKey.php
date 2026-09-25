<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * A named mutual-exclusion scope.
 *
 * Global acquisition order, followed by EVERY flow that locks: the endpoint key
 * first, then the owner keys sorted by value. A total order is what makes deadlocks
 * impossible.
 */
final readonly class LockKey
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function createForEndpoint(EndpointFingerprint $fingerprint): self
    {
        return new self('webpush:endpoint:'.$fingerprint->toString());
    }

    public static function createForSubscriber(SubscriberId $subscriberId): self
    {
        // Hashed: the key is sent to a lock store and should not carry the identity.
        return new self('webpush:owner:'.hash('sha256', $subscriberId->toString()));
    }

    /**
     * @param list<self> $keys
     *
     * @return list<self> endpoint keys first, then owner keys, each group sorted, duplicates removed
     */
    public static function inAcquisitionOrder(array $keys): array
    {
        $unique = [];
        foreach ($keys as $key) {
            $unique[$key->value] = $key;
        }

        uksort($unique, static function (string $left, string $right): int {
            $leftRank = str_starts_with($left, 'webpush:endpoint:') ? 0 : 1;
            $rightRank = str_starts_with($right, 'webpush:endpoint:') ? 0 : 1;

            return [$leftRank, $left] <=> [$rightRank, $right];
        });

        return array_values($unique);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
