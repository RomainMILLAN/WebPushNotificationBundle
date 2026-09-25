<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use Psr\Log\LoggerInterface;
use RomainMillan\WebPushNotification\Application\Port\DeliveryOutcomeListener;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;

/**
 * RetireOnExpiry first — by type, it cannot be left out — then the application
 * listeners, whose failures are logged and never break the delivery.
 */
final readonly class DeliveryOutcomeListeners
{
    /**
     * @param iterable<DeliveryOutcomeListener> $applicationListeners
     */
    public function __construct(
        private RetireOnExpiry $retireOnExpiry,
        private iterable $applicationListeners,
        private LoggerInterface $logger,
    ) {
    }

    public function notify(SubscriptionId $subscriptionId, DeliveryOutcome $outcome): void
    {
        $this->retireOnExpiry->onDeliveryOutcome($subscriptionId, $outcome);

        foreach ($this->applicationListeners as $listener) {
            try {
                $listener->onDeliveryOutcome($subscriptionId, $outcome);
            } catch (\Throwable $failure) {
                $this->logger->error('Web push delivery listener failed.', [
                    'listener' => $listener::class,
                    'subscription_id' => $subscriptionId->toString(),
                    'exception_class' => $failure::class,
                ]);
            }
        }
    }
}
