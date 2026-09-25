<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Http;

use RomainMillan\WebPushNotification\Application\Exception\InvalidRequest;
use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;
use RomainMillan\WebPushNotification\Domain\Subscription\ContentEncoding;
use RomainMillan\WebPushNotification\Domain\Subscription\PushAddress;
use RomainMillan\WebPushNotification\Domain\Subscription\PushEndpoint;
use RomainMillan\WebPushNotification\Domain\Subscription\SubscriptionKeys;

/**
 * PushSubscription.toJSON() — {endpoint, expirationTime, keys: {p256dh, auth}} — plus
 * the optional contentEncoding the client reads from PushManager. Nothing else is
 * accepted: an explicit shape, not "whatever the client sent".
 */
final readonly class SubscribeRequest
{
    private const ALLOWED_KEYS = ['endpoint', 'expirationTime', 'keys', 'contentEncoding'];

    private function __construct(
        private PushAddress $address,
    ) {
    }

    public static function fromBody(JsonRequestBody $body): self
    {
        $data = $body->toArray();

        if ([] !== array_diff(array_keys($data), self::ALLOWED_KEYS)) {
            throw InvalidRequest::because('Cannot accept a subscription carrying unknown fields.');
        }

        $keys = $data['keys'] ?? null;

        if (!\is_string($data['endpoint'] ?? null) || !\is_array($keys) || !\is_string($keys['p256dh'] ?? null) || !\is_string($keys['auth'] ?? null)) {
            throw InvalidRequest::because('Cannot accept a subscription without endpoint, keys.p256dh and keys.auth strings.');
        }

        $encoding = $data['contentEncoding'] ?? ContentEncoding::Aes128Gcm->value;

        try {
            return new self(new PushAddress(
                PushEndpoint::fromString($data['endpoint']),
                SubscriptionKeys::fromStrings($keys['p256dh'], $keys['auth']),
                \is_string($encoding) ? ContentEncoding::from($encoding) : throw InvalidRequest::because('Cannot accept a non-string content encoding.'),
            ));
        } catch (\ValueError) {
            throw InvalidRequest::because('Cannot accept an unsupported content encoding.');
        } catch (WebPushNotificationException $invalid) {
            throw InvalidRequest::because($invalid->getMessage());
        }
    }

    public function address(): PushAddress
    {
        return $this->address;
    }
}
