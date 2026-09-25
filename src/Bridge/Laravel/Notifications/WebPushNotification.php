<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Notifications;

use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

/** A Laravel notification sent through WebPushChannel (via(): [WebPushChannel::class] or 'web-push'). */
interface WebPushNotification
{
    public function toWebPush(object $notifiable): WebPushMessage;
}
