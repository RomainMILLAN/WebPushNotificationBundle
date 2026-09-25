<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pushed onto the "web" group by the service provider. A page that rendered.
 *
 * @webPushMeta carries a CSRF token and the client state marker of its user: no
 * shared cache may keep it, whatever cache headers the application set.
 *
 * The directive only flags the request — rendering a view has no side effect on the
 * response itself.
 */
final readonly class MarkWebPushResponsePrivate
{
    public const REQUEST_ATTRIBUTE = '_web_push_meta_rendered';

    public function handle(Request $request, \Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response && true === $request->attributes->get(self::REQUEST_ATTRIBUTE)) {
            $response->setPrivate();
            $response->headers->removeCacheControlDirective('s-maxage');
        }

        return $response;
    }
}
