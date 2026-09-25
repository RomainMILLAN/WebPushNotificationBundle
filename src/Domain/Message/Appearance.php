<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

/**
 * How the notification looks and how loudly it asks for attention: icon, badge, tag,
 * attention. Absent images fall back to the service worker defaults.
 */
final readonly class Appearance
{
    /**
     * @param list<AssetUrl> $icon  zero or one
     * @param list<AssetUrl> $badge zero or one
     */
    private function __construct(
        private array $icon,
        private array $badge,
        private Tag $tag,
        private Attention $attention,
    ) {
    }

    public static function createTagged(Tag $tag): self
    {
        return new self([], [], $tag, Attention::Normal);
    }

    public function withIcon(AssetUrl $icon): self
    {
        return new self([$icon], $this->badge, $this->tag, $this->attention);
    }

    public function withBadge(AssetUrl $badge): self
    {
        return new self($this->icon, [$badge], $this->tag, $this->attention);
    }

    public function withTag(Tag $tag): self
    {
        return new self($this->icon, $this->badge, $tag, $this->attention);
    }

    public function silent(): self
    {
        return new self($this->icon, $this->badge, $this->tag, Attention::Silent);
    }

    /**
     * Renotify on a unique default tag would never replace anything: an insistent
     * notification needs a tag chosen by the application.
     */
    public function insistent(): self
    {
        if (!$this->tag->isExplicit()) {
            throw InvalidValue::because('Cannot make a notification insistent without an explicit tag.');
        }

        return new self($this->icon, $this->badge, $this->tag, Attention::Insistent);
    }

    /**
     * @return array<string, string|bool>
     */
    public function toPayload(): array
    {
        $payload = ['tag' => $this->tag->toString()] + $this->attention->toPayload();

        foreach ($this->icon as $icon) {
            $payload['icon'] = $icon->toString();
        }

        foreach ($this->badge as $badge) {
            $payload['badge'] = $badge->toString();
        }

        return $payload;
    }
}
