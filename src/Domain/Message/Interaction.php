<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Message\Action\NotificationAction;

/**
 * What a user can do with the notification: where a click leads, the action buttons,
 * the application badge count and the free data for the worker.
 */
final readonly class Interaction
{
    public const MAX_ACTIONS = 2;

    /**
     * @param list<ClickPath>          $click      zero or one
     * @param list<NotificationAction> $actions
     * @param list<int>                $badgeCount zero or one
     */
    private function __construct(
        private array $click,
        private array $actions,
        private array $badgeCount,
        private MessageData $data,
    ) {
    }

    public static function createInert(): self
    {
        return new self([], [], [], MessageData::empty());
    }

    public function withClickPath(ClickPath $path): self
    {
        return new self([$path], $this->actions, $this->badgeCount, $this->data);
    }

    public function withAction(NotificationAction $action): self
    {
        // Browsers display two buttons at most (Notification.maxActions): a third one
        // would silently vanish.
        if (\count($this->actions) >= self::MAX_ACTIONS) {
            throw InvalidValue::because(\sprintf('Cannot add more than %d actions to a notification.', self::MAX_ACTIONS));
        }

        return new self($this->click, [...$this->actions, $action], $this->badgeCount, $this->data);
    }

    public function withBadgeCount(int $count): self
    {
        if ($count < 0) {
            throw InvalidValue::because('Cannot accept a negative badge count.');
        }

        return new self($this->click, $this->actions, [$count], $this->data);
    }

    public function withData(MessageData $data): self
    {
        return new self($this->click, $this->actions, $this->badgeCount, $data);
    }

    /**
     * @return array{actions: list<array{action: string, title: string, type: string, url?: string}>, data: array<string, string|int|float|bool>, click?: string, badgeCount?: int}
     */
    public function toPayload(): array
    {
        $payload = [
            'actions' => array_map(static fn (NotificationAction $action): array => $action->toPayload(), $this->actions),
            'data' => $this->data->toPayload(),
        ];

        foreach ($this->click as $click) {
            $payload['click'] = $click->toString();
        }

        foreach ($this->badgeCount as $badgeCount) {
            $payload['badgeCount'] = $badgeCount;
        }

        return $payload;
    }
}
