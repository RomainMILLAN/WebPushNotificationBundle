<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Minishlink;

use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription as MinishlinkSubscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use RomainMillan\WebPushNotification\Application\Contract\EncodedPayload;
use RomainMillan\WebPushNotification\Application\Port\PushTransport;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryReport;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryTarget;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Infrastructure\Network\HostPinning;

/**
 * Anticorruption layer over minishlink/web-push.
 *
 * Translation: our p256dh/auth are Minishlink's publicKey/authToken, its
 * MessageSentReport becomes a classified DeliveryOutcome.
 *
 * NEVER uses MessageSentReport::getReason() nor an exception message: both embed the
 * request URL — the capability endpoint. Logs carry the push service host, the
 * fingerprint prefix, the status and the category; exceptions are reduced to their
 * class name.
 */
final readonly class MinishlinkPushTransport implements PushTransport
{
    public function __construct(
        private WebPushClientFactory $webPushClientFactory,
        private HttpStatusClassifier $httpStatusClassifier,
        private HostPinning $hostPinning,
        private LoggerInterface $logger,
    ) {
    }

    public function deliver(array $targets, EncodedPayload $payload, DeliveryOptions $options): DeliveryReport
    {
        $report = DeliveryReport::empty();
        $plan = $this->hostPinning->planFor(array_map(static fn (DeliveryTarget $target): string => $target->host(), $targets));
        $deliverable = [];

        foreach ($targets as $target) {
            if ($plan->isUnsafe($target->host())) {
                $this->logger->warning('Web push delivery refused: the push service host does not resolve to public addresses only.', $this->context($target));
                $report = $report->recordOutcome($target->subscriptionId(), DeliveryOutcome::permanent(FailureCategory::Unsafe));
                continue;
            }

            $deliverable[] = $target;
        }

        if ([] === $deliverable) {
            return $report;
        }

        $client = $this->webPushClientFactory->createClient($plan->resolveEntries());
        $queued = [];

        foreach ($deliverable as $target) {
            $revealed = $target->reveal();

            try {
                $client->queueNotification(
                    new MinishlinkSubscription($revealed['endpoint'], $revealed['p256dh'], $revealed['auth'], $revealed['encoding']),
                    $payload->toString(),
                    $options->toTransportOptions(),
                );
                $queued[] = $target;
            } catch (\Throwable $failure) {
                $this->logger->error('Web push notification could not be queued.', $this->context($target) + ['exception_class' => $failure::class]);
                $report = $report->recordOutcome($target->subscriptionId(), DeliveryOutcome::permanent(FailureCategory::Payload));
            }
        }

        return $this->flush($client, $queued, $report);
    }

    /**
     * Minishlink yields reports in queue order: the index maps a report back to its
     * target without ever reading the endpoint back from the report.
     *
     * @param list<DeliveryTarget> $queued
     */
    private function flush(WebPush $client, array $queued, DeliveryReport $report): DeliveryReport
    {
        $index = 0;

        try {
            /** @var MessageSentReport $sent */
            foreach ($client->flush() as $sent) {
                if (!isset($queued[$index])) {
                    break;
                }

                $target = $queued[$index++];

                $response = $sent->getResponse();
                $outcome = null !== $response
                    ? $this->httpStatusClassifier->classifyResponse($response)
                    : $this->httpStatusClassifier->classifyMissingResponse();

                $this->logOutcome($target, $outcome);
                $report = $report->recordOutcome($target->subscriptionId(), $outcome);
            }
        } catch (\Throwable $failure) {
            $this->logger->error('Web push flush failed.', ['exception_class' => $failure::class]);
        }

        foreach ($queued as $target) {
            if (!$report->hasOutcomeFor($target->subscriptionId())) {
                $report = $report->recordOutcome($target->subscriptionId(), $this->httpStatusClassifier->classifyMissingResponse());
            }
        }

        return $report;
    }

    private function logOutcome(DeliveryTarget $target, DeliveryOutcome $outcome): void
    {
        $context = $this->context($target) + ['status' => $outcome->httpStatus, 'category' => $outcome->category->value];

        match (true) {
            $outcome->isDelivered() => null,
            $outcome->isExpired() => $this->logger->info('Web push subscription expired.', $context),
            FailureCategory::Vapid === $outcome->category => $this->logger->critical('Web push rejected the VAPID signature: check the key pair (rotation?).', $context),
            default => $this->logger->warning('Web push delivery failed.', $context),
        };
    }

    /**
     * @return array<string, string>
     */
    private function context(DeliveryTarget $target): array
    {
        return [
            'subscription_id' => $target->subscriptionId()->toString(),
            'push_service_host' => $target->host(),
            'fingerprint' => $target->shortFingerprint(),
        ];
    }
}
