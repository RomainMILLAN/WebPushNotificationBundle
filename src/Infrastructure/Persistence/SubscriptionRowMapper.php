<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Persistence;

use RomainMillan\WebPushNotification\Application\Port\SubscriptionCipher;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\ContentEncoding;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Lifecycle;
use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;
use RomainMillan\WebPushNotification\Domain\Subscription\Retirement;
use RomainMillan\WebPushNotification\Domain\Subscription\RetirementReason;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionKeys;

/**
 * Data Mapper shared by the Doctrine and Eloquent adapters: aggregate <-> row of the
 * web_push_subscription table. The cipher is applied HERE, with the fingerprint and
 * the column name as associated data; the domain stays unaware of persistence.
 *
 * Reconstitution re-validates the endpoint SHAPE (a tampered row cannot become an
 * SSRF vector), never the allowlist (which is checked at delivery).
 *
 * @phpstan-type SubscriptionRow array{
 *     id: string,
 *     owner_type: string,
 *     subscriber_id: ?string,
 *     endpoint: string,
 *     endpoint_hash: string,
 *     push_host: string,
 *     p256dh: string,
 *     auth: string,
 *     content_encoding: string,
 *     registered_at: \DateTimeImmutable,
 *     last_registered_at: \DateTimeImmutable,
 *     retired_at: ?\DateTimeImmutable,
 *     retirement_reason: ?string,
 * }
 */
final readonly class SubscriptionRowMapper
{
    public const OWNER_IDENTIFIED = 'identified';
    public const OWNER_ANONYMOUS = 'anonymous';

    public function __construct(
        private SubscriptionCipher $subscriptionCipher,
    ) {
    }

    /**
     * @return SubscriptionRow
     */
    public function toRow(Subscription $subscription): array
    {
        $snapshot = $subscription->snapshot();
        $hash = $snapshot->address['endpoint_hash'];

        return [
            'id' => $snapshot->id,
            'owner_type' => null === $snapshot->subscriberId ? self::OWNER_ANONYMOUS : self::OWNER_IDENTIFIED,
            'subscriber_id' => $snapshot->subscriberId,
            'endpoint' => $this->subscriptionCipher->encrypt($snapshot->address['endpoint'], $hash.':endpoint'),
            'endpoint_hash' => $hash,
            // Not a secret ("fcm.googleapis.com"): lets the read model name the push
            // service without decrypting anything.
            'push_host' => $snapshot->address['host'],
            'p256dh' => $this->subscriptionCipher->encrypt($snapshot->address['p256dh'], $hash.':p256dh'),
            'auth' => $this->subscriptionCipher->encrypt($snapshot->address['auth'], $hash.':auth'),
            'content_encoding' => $snapshot->address['content_encoding'],
            'registered_at' => $snapshot->lifecycle['registered_at'],
            'last_registered_at' => $snapshot->lifecycle['last_registered_at'],
            'retired_at' => $snapshot->lifecycle['retired_at'],
            'retirement_reason' => $snapshot->lifecycle['retirement_reason'],
        ];
    }

    /**
     * @param SubscriptionRow $row
     */
    public function fromRow(array $row): Subscription
    {
        $hash = $row['endpoint_hash'];

        $address = new PushAddress(
            PushEndpoint::fromString($this->subscriptionCipher->decrypt($row['endpoint'], $hash.':endpoint')),
            SubscriptionKeys::fromStrings(
                $this->subscriptionCipher->decrypt($row['p256dh'], $hash.':p256dh'),
                $this->subscriptionCipher->decrypt($row['auth'], $hash.':auth'),
            ),
            ContentEncoding::from($row['content_encoding']),
        );

        $owner = self::OWNER_IDENTIFIED === $row['owner_type'] && null !== $row['subscriber_id']
            ? IdentifiedOwner::fromSubscriberId($row['subscriber_id'])
            : new AnonymousOwner();

        $lifecycle = null !== $row['retired_at'] && null !== $row['retirement_reason']
            ? Lifecycle::reconstituteRetired($row['registered_at'], $row['last_registered_at'], new Retirement(RetirementReason::from($row['retirement_reason']), $row['retired_at']))
            : Lifecycle::reconstituteActive($row['registered_at'], $row['last_registered_at']);

        return Subscription::reconstitute(SubscriptionId::fromString($row['id']), $owner, $address, $lifecycle);
    }
}
