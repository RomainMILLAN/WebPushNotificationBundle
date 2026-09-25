<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel\Fixtures;

use Illuminate\Notifications\Notification;
use RomainMillan\WebPushNotification\Bridge\Laravel\Notifications\WebPushChannel;
use RomainMillan\WebPushNotification\Bridge\Laravel\Notifications\WebPushNotification;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

final class OrderShipped extends Notification implements WebPushNotification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return WebPushMessage::createWithTitle('Order shipped', 'Your parcel is on its way.');
    }
}
