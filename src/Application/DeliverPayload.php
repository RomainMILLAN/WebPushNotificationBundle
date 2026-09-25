<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Application\Contract\EncodedPayload;
use RomainMillan\WebPushNotification\Application\Port\PushTransport;
use RomainMillan\WebPushNotification\Domain\Delivery\Audience;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryReport;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Subscription\AllowedPushServices;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionId;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionRepository;

/**
 * THE delivery path. Synchronous sends, Messenger handlers and Laravel jobs all go
 * through here, so the checks exist once:
 * - the audience still admits the subscription (active, same owner as when selected);
 * - its host is still an allowed push service (a host removed from the configuration
 *   stops deliveries at once);
 * then the transport, then RetireOnExpiry, then the application listeners.
 */
final readonly class DeliverPayload
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private PushTransport $pushTransport,
        private AllowedPushServices $allowedPushServices,
        private DeliveryOutcomeListeners $deliveryOutcomeListeners,
    ) {
    }

    public function deliver(Audience $audience, EncodedPayload $payload, DeliveryOptions $options): DeliveryReport
    {
        $report = DeliveryReport::empty();

        foreach ($audience->selectFrom($this->subscriptionRepository, self::BATCH_SIZE) as $batch) {
            $targets = [];

            foreach ($batch as $subscription) {
                $admission = $audience->admits($subscription);

                if (FailureCategory::None !== $admission) {
                    $report = $report->recordOutcome($subscription->id(), DeliveryOutcome::skipped($admission));
                    continue;
                }

                if (!$subscription->isServedBy($this->allowedPushServices)) {
                    $report = $report->recordOutcome($subscription->id(), DeliveryOutcome::skipped(FailureCategory::HostNotAllowed));
                    continue;
                }

                $targets[] = $subscription->deliveryTarget();
            }

            if ([] !== $targets) {
                $report = $report->merge($this->pushTransport->deliver($targets, $payload, $options));
            }
        }

        foreach ($report as $id => $outcome) {
            $this->deliveryOutcomeListeners->notify(SubscriptionId::fromString($id), $outcome);
        }

        return $report;
    }
}
