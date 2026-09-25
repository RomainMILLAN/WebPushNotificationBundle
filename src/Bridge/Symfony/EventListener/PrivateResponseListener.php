<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * A page rendering web_push_meta() carries a CSRF token and a client state marker of
 * ONE user: a shared cache (reverse proxy, ESI) must never serve it to another.
 */
final readonly class PrivateResponseListener
{
    public const ATTRIBUTE = '_web_push_private';

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || true !== $event->getRequest()->attributes->get(self::ATTRIBUTE)) {
            return;
        }

        $event->getResponse()->setPrivate();
        $event->getResponse()->headers->addCacheControlDirective('no-store');
    }
}
