<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Http;

use RomainMillan\WebPushNotification\Application\Http\ForbiddenRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The JSON endpoints are protected by a CSRF token sent in a header — the page reads
 * it from <meta name="web-push-config">.
 */
final readonly class CsrfHeader
{
    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
        private string $tokenId,
        private string $headerName,
    ) {
    }

    public function assertValid(Request $request): void
    {
        $token = $request->headers->get($this->headerName, '');

        if ('' === $token || !$this->csrfTokenManager->isTokenValid(new CsrfToken($this->tokenId, $token))) {
            throw ForbiddenRequest::invalidCsrfToken();
        }
    }

    public function headerName(): string
    {
        return $this->headerName;
    }

    public function currentToken(): string
    {
        return $this->csrfTokenManager->getToken($this->tokenId)->getValue();
    }
}
