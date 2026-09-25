<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Subscription;

/**
 * aes128gcm is RFC 8291 and what the client announces. aesgcm is deprecated and only
 * kept so rows registered by older browsers stay deliverable.
 */
enum ContentEncoding: string
{
    case Aes128Gcm = 'aes128gcm';
    case AesGcm = 'aesgcm';
}
