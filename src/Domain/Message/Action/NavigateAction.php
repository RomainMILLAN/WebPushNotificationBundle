<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message\Action;

use RomainMillan\WebPushNotification\Domain\Message\ClickPath;

/** Opens (or focuses) the application on an in-origin path. */
final readonly class NavigateAction implements NotificationAction
{
    public function __construct(
        private ActionLabel $label,
        private ClickPath $path,
    ) {
    }

    public function toPayload(): array
    {
        return $this->label->toPayload() + ['type' => 'navigate', 'url' => $this->path->toString()];
    }
}
