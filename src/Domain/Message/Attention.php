<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

/**
 * How loudly the notification asks for attention.
 */
enum Attention: string
{
    case Normal = 'normal';

    /** No sound nor vibration. */
    case Silent = 'silent';

    /** Stays on screen until dismissed and alerts again when it replaces one with the same tag. Requires a tag. */
    case Insistent = 'insistent';

    /**
     * @return array{silent: bool, requireInteraction: bool, renotify: bool}
     */
    public function toPayload(): array
    {
        return [
            'silent' => self::Silent === $this,
            'requireInteraction' => self::Insistent === $this,
            'renotify' => self::Insistent === $this,
        ];
    }
}
