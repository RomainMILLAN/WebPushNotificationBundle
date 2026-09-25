<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message\Action;

/** Closes the notification, nothing else. */
final readonly class DismissAction implements NotificationAction
{
    public function __construct(
        private ActionLabel $label,
    ) {
    }

    public function toPayload(): array
    {
        return $this->label->toPayload() + ['type' => 'dismiss'];
    }
}
