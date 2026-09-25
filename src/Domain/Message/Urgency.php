<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

/** RFC 8030 §5.3 — lets the push service save the device's battery. */
enum Urgency: string
{
    case VeryLow = 'very-low';
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
