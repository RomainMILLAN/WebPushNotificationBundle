<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionAlreadyExists;
use RomainMillan\WebPushNotification\Domain\Exception\SubscriptionNotFound;

/**
 * Write-side port, collection-like. Replaceable: every implementation must pass
 * Testing\SubscriptionRepositoryContract.
 *
 * Contract:
 * - calls happen inside TransactionBoundary::run();
 * - save() throws SubscriptionAlreadyExists when another row holds the endpoint hash
 *   (unique constraint), whatever the storage;
 * - ownedBy(AnonymousOwner) returns an empty collection (anonymous subscriptions have
 *   no per-owner quota);
 * - lookups by fingerprint include retired subscriptions.
 */
interface SubscriptionRepository
{
    /**
     * @throws SubscriptionNotFound
     */
    public function get(SubscriptionId $id): Subscription;

    /** Retired subscriptions included. */
    public function hasFingerprint(EndpointFingerprint $fingerprint): bool;

    /**
     * @throws SubscriptionNotFound
     */
    public function getByFingerprint(EndpointFingerprint $fingerprint): Subscription;

    /** Active subscriptions of an identified owner; empty for AnonymousOwner. */
    public function ownedBy(Owner $owner): OwnerSubscriptions;

    /**
     * The owner filter is part of the query: no find-then-check.
     *
     * @throws SubscriptionNotFound also when the subscription belongs to someone else
     */
    public function getOwnedSubscription(Owner $owner, SubscriptionId $id): Subscription;

    /**
     * @param list<SubscriptionId> $ids
     *
     * @return list<Subscription> missing ids are omitted
     */
    public function getMany(array $ids): array;

    /**
     * Every active subscription, by id cursor, never hydrating everything at once.
     *
     * @param positive-int $size
     *
     * @return iterable<list<Subscription>>
     */
    public function activeInBatches(int $size): iterable;

    public function countActiveAnonymous(): int;

    /**
     * @throws SubscriptionAlreadyExists
     */
    public function save(Subscription $subscription): void;

    public function remove(Subscription $subscription): void;
}
