<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RomainMillan\WebPushNotification\Application\DeliverPayload;
use RomainMillan\WebPushNotification\Application\Queue\QueuedDelivery;

/**
 * One queued delivery to one subscription. Scalars only (QueuedDelivery::toArray()):
 * no model, no serialized object graph, never an endpoint — a deployment never
 * breaks pending jobs, and failed_jobs holds no capability URL.
 *
 * Goes through DeliverPayload like every other send: a subscription retired, deleted
 * or handed to another owner since the dispatch is dropped, never retried.
 */
final class SendWebPushJob implements ShouldQueue
{
    use Queueable;

    private const DEFAULT_RETRY_SECONDS = 60;

    public int $tries = 3;

    /**
     * @param array{subscription_id: string, expected_subscriber_id: string, payload: string, options: array{ttl: int, urgency: string, topic: string}} $delivery
     */
    public function __construct(
        public readonly array $delivery,
    ) {
    }

    public function handle(DeliverPayload $deliverPayload): void
    {
        $delivery = QueuedDelivery::fromArray($this->delivery);

        $report = $deliverPayload->deliver($delivery->audience(), $delivery->payload(), $delivery->options());

        // Only a transient failure (429, 5xx, network) is worth another attempt, after
        // the push service's Retry-After when it gave one.
        if ($report->hasRetryableFailure() && $this->attempts() < $this->tries) {
            $this->release(0 < $report->retryAfterSeconds() ? $report->retryAfterSeconds() : self::DEFAULT_RETRY_SECONDS);
        }
    }
}
