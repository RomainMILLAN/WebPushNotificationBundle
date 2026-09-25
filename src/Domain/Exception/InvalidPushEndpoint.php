<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Exception;

/**
 * Messages never repeat the endpoint: it is a capability URL, and exception messages
 * end up in logs, error trackers and failure transports.
 */
final class InvalidPushEndpoint extends \DomainException implements WebPushNotificationException
{
    public static function tooLong(int $maxLength): self
    {
        return new self(\sprintf('Cannot accept a push endpoint longer than %d characters.', $maxLength));
    }

    public static function notAnUrl(): self
    {
        return new self('Cannot accept a push endpoint that is not a valid URL.');
    }

    public static function notHttps(): self
    {
        return new self('Cannot accept a push endpoint that is not https.');
    }

    public static function carryingUserInfo(): self
    {
        return new self('Cannot accept a push endpoint carrying user information.');
    }

    public static function carryingExplicitPort(): self
    {
        return new self('Cannot accept a push endpoint carrying an explicit port.');
    }

    public static function hostIsAnIpLiteral(): self
    {
        return new self('Cannot accept a push endpoint whose host is an IP literal.');
    }

    public static function carryingForbiddenCharacters(): self
    {
        return new self('Cannot accept a push endpoint carrying whitespace, control characters or backslashes.');
    }

    public static function notCanonical(): self
    {
        return new self('Cannot accept a push endpoint that is not in canonical form.');
    }
}
