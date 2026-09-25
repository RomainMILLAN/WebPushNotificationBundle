<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message\Action;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

use function Symfony\Component\String\u;

/** The identifier and visible title of an action button. */
final readonly class ActionLabel
{
    private function __construct(
        private string $action,
        private string $title,
    ) {
    }

    public static function fromActionAndTitle(string $action, string $title): self
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $action)) {
            throw InvalidValue::because('Cannot accept an action identifier that is not 1 to 32 lowercase characters starting with a letter.');
        }

        if (u($title)->trim()->isEmpty() || u($title)->length() > 40 || 1 === preg_match('/[\x00-\x1F\x7F]/', $title)) {
            throw InvalidValue::because('Cannot accept an action title that is blank, longer than 40 characters or carries control characters.');
        }

        return new self($action, $title);
    }

    /**
     * @return array{action: string, title: string}
     */
    public function toPayload(): array
    {
        return ['action' => $this->action, 'title' => $this->title];
    }
}
