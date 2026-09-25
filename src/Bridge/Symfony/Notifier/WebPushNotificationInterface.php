<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Notifier;

use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

/**
 * Implemented by a Notification that builds its own web push message (click path,
 * actions, tag...). Otherwise the channel uses the subject and content.
 */
interface WebPushNotificationInterface
{
    public function asWebPushMessage(WebPushRecipientInterface $recipient): WebPushMessage;
}
