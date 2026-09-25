<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Message\Action\NotificationAction;

/**
 * A notification, independent of who receives it and how it travels.
 *
 * Immutable, built with withers:
 *
 *     WebPushMessage::createWithTitle('Payment received', '120 € from ACME')
 *         ->withClickPath(ClickPath::fromString('/app/payments/42'))
 *         ->withTag(Tag::fromString('payment-42'))
 *         ->insistent();
 *
 * Its id defaults the tag, so a duplicate display caused by a retry replaces the
 * first one instead of stacking.
 */
final readonly class WebPushMessage
{
    private function __construct(
        private string $id,
        private Content $content,
        private Appearance $appearance,
        private Interaction $interaction,
    ) {
    }

    public static function createWithTitle(string $title, string $body = ''): self
    {
        $id = bin2hex(random_bytes(8));

        return new self(
            $id,
            Content::fromTitleAndBody($title, $body),
            Appearance::createTagged(Tag::createDefaultForMessage($id)),
            Interaction::createInert(),
        );
    }

    public function withClickPath(ClickPath $path): self
    {
        return new self($this->id, $this->content, $this->appearance, $this->interaction->withClickPath($path));
    }

    public function withAction(NotificationAction $action): self
    {
        return new self($this->id, $this->content, $this->appearance, $this->interaction->withAction($action));
    }

    public function withBadgeCount(int $count): self
    {
        return new self($this->id, $this->content, $this->appearance, $this->interaction->withBadgeCount($count));
    }

    public function withData(MessageData $data): self
    {
        return new self($this->id, $this->content, $this->appearance, $this->interaction->withData($data));
    }

    public function withIcon(AssetUrl $icon): self
    {
        return new self($this->id, $this->content, $this->appearance->withIcon($icon), $this->interaction);
    }

    public function withBadge(AssetUrl $badge): self
    {
        return new self($this->id, $this->content, $this->appearance->withBadge($badge), $this->interaction);
    }

    /** Notifications sharing a tag replace each other (e.g. "alert-42"). */
    public function withTag(Tag $tag): self
    {
        return new self($this->id, $this->content, $this->appearance->withTag($tag), $this->interaction);
    }

    public function silent(): self
    {
        return new self($this->id, $this->content, $this->appearance->silent(), $this->interaction);
    }

    /** Stays until dismissed; requires an explicit tag (see Appearance::insistent()). */
    public function insistent(): self
    {
        return new self($this->id, $this->content, $this->appearance->insistent(), $this->interaction);
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * The payload body — contract v1 without the version, which PayloadEncoder adds.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return ['id' => $this->id]
            + $this->content->toPayload()
            + $this->appearance->toPayload()
            + $this->interaction->toPayload();
    }
}
