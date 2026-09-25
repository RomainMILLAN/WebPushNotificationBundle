<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application\Exception;

use RomainMillan\WebPushNotification\Domain\Exception\WebPushNotificationException;

/** Answered as 413. */
final class RequestTooLarge extends \InvalidArgumentException implements WebPushNotificationException
{
    public static function create(int $maxBytes): self
    {
        return new self(\sprintf('Cannot accept a request body larger than %d bytes.', $maxBytes));
    }
}
