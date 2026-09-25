<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Http;

use RomainMillan\WebPushNotification\Application\Exception\InvalidRequest;
use RomainMillan\WebPushNotification\Application\Proof\PossessionProof;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;

/**
 * {endpoint, keys: {auth}} — the same body as a subscription (p256dh and extra
 * PushSubscription fields are tolerated and ignored), because the device proves
 * possession with its auth secret.
 */
final readonly class UnsubscribeRequest
{
    private function __construct(
        private PushEndpoint $endpoint,
        private PossessionProof $proof,
    ) {
    }

    public static function fromBody(JsonRequestBody $body): self
    {
        $data = $body->toArray();
        $keys = $data['keys'] ?? null;

        if (!\is_string($data['endpoint'] ?? null) || !\is_array($keys) || !\is_string($keys['auth'] ?? null)) {
            throw InvalidRequest::because('Cannot accept an unsubscription without endpoint and keys.auth strings.');
        }

        try {
            return new self(PushEndpoint::fromString($data['endpoint']), new PossessionProof($keys['auth']));
        } catch (WebPushNotificationException $invalid) {
            throw InvalidRequest::because($invalid->getMessage());
        }
    }

    public function endpoint(): PushEndpoint
    {
        return $this->endpoint;
    }

    public function proof(): PossessionProof
    {
        return $this->proof;
    }
}
