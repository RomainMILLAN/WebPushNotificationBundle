<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Queue;

use RomainMillan\WebPushNotification\Application\Contract\EncodedPayload;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriptionsAudience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Subscription\AnonymousOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Domain\Subscription\Subscription;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

/**
 * One queued delivery, scalars only — the format of Messenger messages and Laravel
 * jobs alike:
 * - one per subscription, so a retry concerns one device;
 * - the owner at dispatch time: consumed after the device changed hands, the message
 *   is dropped instead of reaching the new owner;
 * - the payload as contract v1 JSON, never a serialized PHP object graph, so a
 *   deployment never breaks pending messages. Its text therefore sits in the broker:
 *   see docs/security.md.
 */
final readonly class QueuedDelivery
{
    /**
     * @param array{ttl: int, urgency: string, topic: string} $options
     */
    private function __construct(
        private string $subscriptionId,
        private string $expectedSubscriberId,
        private string $payload,
        private array $options,
    ) {
    }

    public static function createForSubscription(Subscription $subscription, EncodedPayload $payload, DeliveryOptions $options): self
    {
        return new self(
            $subscription->id()->toString(),
            $subscription->snapshot()->subscriberId ?? '',
            $payload->toString(),
            $options->toArray(),
        );
    }

    /**
     * @param array<mixed> $queued
     */
    public static function fromArray(array $queued): self
    {
        $options = $queued['options'] ?? null;

        if (!\is_string($queued['subscription_id'] ?? null) || !\is_string($queued['expected_subscriber_id'] ?? null) || !\is_string($queued['payload'] ?? null)
            || !\is_array($options) || !\is_int($options['ttl'] ?? null) || !\is_string($options['urgency'] ?? null) || !\is_string($options['topic'] ?? null)) {
            throw new \InvalidArgumentException('Cannot restore a queued web push delivery from a malformed message.');
        }

        return new self($queued['subscription_id'], $queued['expected_subscriber_id'], $queued['payload'], ['ttl' => $options['ttl'], 'urgency' => $options['urgency'], 'topic' => $options['topic']]);
    }

    public function audience(): SubscriptionsAudience
    {
        return new SubscriptionsAudience(
            [SubscriptionId::fromString($this->subscriptionId)],
            '' === $this->expectedSubscriberId ? new AnonymousOwner() : IdentifiedOwner::fromSubscriberId($this->expectedSubscriberId),
        );
    }

    public function payload(): EncodedPayload
    {
        return EncodedPayload::fromQueuedJson($this->payload);
    }

    public function options(): DeliveryOptions
    {
        return DeliveryOptions::fromArray($this->options);
    }

    /**
     * @return array{subscription_id: string, expected_subscriber_id: string, payload: string, options: array{ttl: int, urgency: string, topic: string}}
     */
    public function toArray(): array
    {
        return ['subscription_id' => $this->subscriptionId, 'expected_subscriber_id' => $this->expectedSubscriberId, 'payload' => $this->payload, 'options' => $this->options];
    }
}
