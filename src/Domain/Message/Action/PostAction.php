<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message\Action;

use RomainMillan\WebPushNotification\Domain\Message\ActionUrl;

/**
 * The service worker POSTs to a same-origin URL without opening the application
 * (e.g. "acknowledge"). The URL must carry its own authorization: see ActionUrl.
 */
final readonly class PostAction implements NotificationAction
{
    public function __construct(
        private ActionLabel $label,
        private ActionUrl $url,
    ) {
    }

    public function toPayload(): array
    {
        return $this->label->toPayload() + ['type' => 'post', 'url' => $this->url->toString()];
    }
}
