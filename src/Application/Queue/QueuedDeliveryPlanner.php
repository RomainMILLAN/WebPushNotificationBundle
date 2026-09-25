<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Queue;

use RomainMillan\WebPushNotification\Application\Contract\PayloadEncoder;
use RomainMillan\WebPushNotification\Domain\Delivery\Audience;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * Fans an audience out into one queued delivery per admitted subscription, batch by
 * batch. Mind the volume of Everyone: one message per subscription.
 */
final readonly class QueuedDeliveryPlanner
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private PayloadEncoder $payloadEncoder,
    ) {
    }

    /**
     * @return iterable<QueuedDelivery>
     */
    public function plan(Audience $audience, WebPushMessage $message, DeliveryOptions $options): iterable
    {
        // Encoded once, before fanning out: an oversized payload fails the dispatch,
        // not every queued message.
        $payload = $this->payloadEncoder->encode($message);

        foreach ($audience->selectFrom($this->subscriptionRepository, self::BATCH_SIZE) as $batch) {
            foreach ($batch as $subscription) {
                if (FailureCategory::None === $audience->admits($subscription)) {
                    yield QueuedDelivery::createForSubscription($subscription, $payload, $options);
                }
            }
        }
    }
}
