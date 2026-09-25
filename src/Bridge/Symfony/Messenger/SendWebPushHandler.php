<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Messenger;

use RomainMillan\WebPushNotification\Application\DeliverPayload;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Goes through the same DeliverPayload as synchronous sends. Only transient failures
 * are retried (honouring Retry-After); a retired, deleted or re-owned subscription
 * is dropped without retry.
 */
final readonly class SendWebPushHandler
{
    public function __construct(
        private DeliverPayload $deliverPayload,
    ) {
    }

    public function __invoke(SendWebPush $message): void
    {
        $delivery = $message->queuedDelivery();
        $report = $this->deliverPayload->deliver($delivery->audience(), $delivery->payload(), $delivery->options());

        if ($report->hasRetryableFailure()) {
            // The 4th argument (retry delay, ms) is honoured by Messenger 7.2+ and
            // ignored before: the transport retry strategy then applies.
            throw new RecoverableMessageHandlingException('The push service asked to retry later.', 0, null, $report->retryAfterSeconds() * 1000);
        }
    }
}
