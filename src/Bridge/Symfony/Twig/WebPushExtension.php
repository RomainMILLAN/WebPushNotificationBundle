<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Twig;

use RomainMillan\WebPushNotification\Application\ClientConfiguration;
use RomainMillan\WebPushNotification\Application\Port\CurrentOwner;
use RomainMillan\WebPushNotification\Bridge\Symfony\EventListener\PrivateResponseListener;
use RomainMillan\WebPushNotification\Bridge\Symfony\Http\CsrfHeader;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ web_push_meta() }} in the <head>. It holds per-user values (CSRF token, client
 * state marker): the function only flags the request, and PrivateResponseListener
 * marks the response private — no side effect on the response from inside Twig.
 *
 * Inheritance imposed by Twig's extension point.
 */
final class WebPushExtension extends AbstractExtension
{
    public function __construct(
        private readonly ClientConfiguration $clientConfiguration,
        private readonly CurrentOwner $currentOwner,
        private readonly CsrfHeader $csrfHeader,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('web_push_meta', $this->renderMeta(...), ['is_safe' => ['html']])];
    }

    public function renderMeta(): string
    {
        $this->requestStack->getMainRequest()?->attributes->set(PrivateResponseListener::ATTRIBUTE, true);

        return $this->clientConfiguration->renderMetaTag($this->currentOwner->resolve(), $this->csrfHeader->headerName(), $this->csrfHeader->currentToken());
    }
}
