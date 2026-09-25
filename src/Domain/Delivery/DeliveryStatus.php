<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Delivery;

enum DeliveryStatus: string
{
    case Delivered = 'delivered';
    case Expired = 'expired';
    case Transient = 'transient';
    case Permanent = 'permanent';
    case Skipped = 'skipped';
}
