<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Http;

use Illuminate\Http\Request;
use RomainMillan\WebPushNotification\Application\Exception\InvalidRequest;

/** The body as a stream: JsonRequestBody reads at most its limit + 1 byte of it. */
final readonly class RequestStream
{
    /**
     * @return resource
     */
    public static function openBodyOf(Request $request)
    {
        $stream = $request->getContent(true);

        return \is_resource($stream) ? $stream : throw InvalidRequest::because('Cannot read the request body as a stream.');
    }
}
