<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Domain\Message;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

/**
 * Push protocol headers (RFC 8030): TTL, Urgency, Topic. Typed, so no header injection
 * can travel through them.
 */
final readonly class DeliveryOptions
{
    public const MAX_TTL = 2419200;

    private function __construct(
        private int $ttl,
        private Urgency $urgency,
        private string $topic,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(self::MAX_TTL, Urgency::Normal, '');
    }

    public function withTtl(int $seconds): self
    {
        if ($seconds < 0 || $seconds > self::MAX_TTL) {
            throw InvalidValue::because(\sprintf('Cannot accept a TTL outside 0..%d seconds.', self::MAX_TTL));
        }

        return new self($seconds, $this->urgency, $this->topic);
    }

    public function withUrgency(Urgency $urgency): self
    {
        return new self($this->ttl, $urgency, $this->topic);
    }

    /** Pending messages with the same topic replace each other at the push service. */
    public function withTopic(string $topic): self
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $topic)) {
            throw InvalidValue::because('Cannot accept a topic that is not 1 to 32 base64url characters.');
        }

        return new self($this->ttl, $this->urgency, $topic);
    }

    /**
     * @return array{TTL: int, urgency: string, topic?: string}
     */
    public function toTransportOptions(): array
    {
        $options = ['TTL' => $this->ttl, 'urgency' => $this->urgency->value];

        if ('' !== $this->topic) {
            $options['topic'] = $this->topic;
        }

        return $options;
    }

    /**
     * Scalar form, for async messages.
     *
     * @return array{ttl: int, urgency: string, topic: string}
     */
    public function toArray(): array
    {
        return ['ttl' => $this->ttl, 'urgency' => $this->urgency->value, 'topic' => $this->topic];
    }

    /**
     * @param array{ttl: int, urgency: string, topic: string} $options
     */
    public static function fromArray(array $options): self
    {
        $restored = self::createDefault()->withTtl($options['ttl'])->withUrgency(Urgency::from($options['urgency']));

        return '' === $options['topic'] ? $restored : $restored->withTopic($options['topic']);
    }
}
