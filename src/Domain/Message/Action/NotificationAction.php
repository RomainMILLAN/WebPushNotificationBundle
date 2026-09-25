<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message\Action;

/**
 * A button on the notification. A sum type — navigate, post, dismiss — so each variant
 * carries exactly the target it needs and the service worker only sees a
 * discriminated "type".
 */
interface NotificationAction
{
    /**
     * @return array{action: string, title: string, type: string, url?: string}
     */
    public function toPayload(): array;
}
