<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony;

use RomainMillan\WebPushNotification\Domain\Message\Origin;
use Symfony\Component\Routing\RequestContext;

/**
 * The application origin: the configured one, else scheme + host + port of the
 * framework.router.default_uri context. Built on first use — an invalid origin fails
 * there, never at container build. The context is the configured one, not the live
 * router context: that one follows the Host header of the current request.
 */
final readonly class OriginFactory
{
    /**
     * @param string $configured the origin setting; empty means "derive it from the request context"
     */
    public static function createFromRequestContext(RequestContext $requestContext, string $configured): Origin
    {
        if ('' !== $configured) {
            return Origin::fromString($configured);
        }

        $scheme = $requestContext->getScheme();
        $port = 'https' === $scheme ? $requestContext->getHttpsPort() : $requestContext->getHttpPort();
        $isDefaultPort = ('https' === $scheme ? 443 : 80) === $port;

        return Origin::fromString($scheme.'://'.$requestContext->getHost().($isDefaultPort ? '' : ':'.$port));
    }
}
