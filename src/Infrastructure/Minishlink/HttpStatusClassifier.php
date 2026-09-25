<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Minishlink;

use Psr\Http\Message\ResponseInterface;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Delivery\FailureCategory;

/**
 * The single place where push service answers become outcomes (docs/security.md):
 *
 * | 2xx         | Delivered                                                    |
 * | 404 / 410   | Expired — the subscription is gone                            |
 * | 429         | Transient (RateLimited), honours Retry-After                  |
 * | 5xx         | Transient (Server), honours Retry-After                       |
 * | no response | Transient (Network): timeout, DNS, TLS                        |
 * | 400 / 413   | Permanent (Payload) — never expires the subscription          |
 * | 401 / 403   | Permanent (Vapid) — typically a VAPID rotation: NOT expired   |
 * | other       | Permanent (Unexpected)                                        |
 */
final readonly class HttpStatusClassifier
{
    private const MAX_RETRY_AFTER = 3600;

    public function classifyResponse(ResponseInterface $response): DeliveryOutcome
    {
        $status = $response->getStatusCode();

        return match (true) {
            $status >= 200 && $status < 300 => DeliveryOutcome::delivered($status),
            404 === $status, 410 === $status => DeliveryOutcome::expired($status),
            429 === $status => DeliveryOutcome::transient(FailureCategory::RateLimited, $status, $this->retryAfter($response)),
            $status >= 500 => DeliveryOutcome::transient(FailureCategory::Server, $status, $this->retryAfter($response)),
            400 === $status, 413 === $status => DeliveryOutcome::permanent(FailureCategory::Payload, $status),
            401 === $status, 403 === $status => DeliveryOutcome::permanent(FailureCategory::Vapid, $status),
            default => DeliveryOutcome::permanent(FailureCategory::Unexpected, $status),
        };
    }

    public function classifyMissingResponse(): DeliveryOutcome
    {
        return DeliveryOutcome::transient(FailureCategory::Network);
    }

    private function retryAfter(ResponseInterface $response): int
    {
        $header = $response->getHeaderLine('Retry-After');

        if (1 === preg_match('/^\d{1,6}$/D', $header)) {
            return min((int) $header, self::MAX_RETRY_AFTER);
        }

        $date = '' !== $header ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $header) : false;

        return false !== $date ? max(0, min($date->getTimestamp() - time(), self::MAX_RETRY_AFTER)) : 0;
    }
}
