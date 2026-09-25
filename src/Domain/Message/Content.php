<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use Symfony\Component\String\Exception\InvalidArgumentException;

use function Symfony\Component\String\u;

/**
 * What the notification says. The body is displayed on the lock screen: never put
 * anything in it that should not be read by someone holding the phone.
 */
final readonly class Content
{
    public const TITLE_MAX_LENGTH = 120;
    public const BODY_MAX_LENGTH = 1000;

    private function __construct(
        private string $title,
        private string $body,
    ) {
    }

    public static function fromTitleAndBody(string $title, string $body = ''): self
    {
        try {
            $titleString = u($title);
            $bodyString = u($body);
        } catch (InvalidArgumentException) {
            throw InvalidValue::because('Cannot accept a notification title or body that is not valid UTF-8.');
        }

        if ($titleString->trim()->isEmpty() || $titleString->length() > self::TITLE_MAX_LENGTH) {
            throw InvalidValue::because(\sprintf('Cannot accept a notification title that is blank or longer than %d characters.', self::TITLE_MAX_LENGTH));
        }

        if ($bodyString->length() > self::BODY_MAX_LENGTH) {
            throw InvalidValue::because(\sprintf('Cannot accept a notification body longer than %d characters.', self::BODY_MAX_LENGTH));
        }

        // Line breaks are fine in a body; other control characters never are.
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $title) || 1 === preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $body)) {
            throw InvalidValue::because('Cannot accept a notification title or body carrying control characters.');
        }

        return new self($title, $body);
    }

    /**
     * @return array{title: string, body: string}
     */
    public function toPayload(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}
