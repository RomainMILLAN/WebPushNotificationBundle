<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Model;
use RomainMillan\WebPushNotification\Infrastructure\Persistence\SubscriptionRowMapper;

/**
 * Persistence record of the web_push_subscription table — not a domain model: the
 * repository turns it into a Subscription through SubscriptionRowMapper.
 *
 * Endpoint and keys are capability secrets: hidden from toArray()/toJson(), so a
 * stray `return $record;` or a log of the model never leaks them.
 *
 * @phpstan-import-type SubscriptionRow from SubscriptionRowMapper
 */
final class SubscriptionRecord extends Model
{
    public const TABLE = 'web_push_subscription';

    public $timestamps = false;

    public $incrementing = false;

    protected $table = self::TABLE;

    protected $keyType = 'string';

    /** @var array<int, string> */
    protected $fillable = [
        'id',
        'owner_type',
        'subscriber_id',
        'endpoint',
        'endpoint_hash',
        'push_host',
        'p256dh',
        'auth',
        'content_encoding',
        'registered_at',
        'last_registered_at',
        'retired_at',
        'retirement_reason',
    ];

    /** @var array<string> */
    protected $hidden = ['endpoint', 'p256dh', 'auth'];

    /**
     * @return SubscriptionRow
     */
    public function toRow(): array
    {
        return [
            'id' => $this->text('id'),
            'owner_type' => $this->text('owner_type'),
            'subscriber_id' => $this->nullableText('subscriber_id'),
            'endpoint' => $this->text('endpoint'),
            'endpoint_hash' => $this->text('endpoint_hash'),
            'push_host' => $this->text('push_host'),
            'p256dh' => $this->text('p256dh'),
            'auth' => $this->text('auth'),
            'content_encoding' => $this->text('content_encoding'),
            'registered_at' => $this->moment('registered_at'),
            'last_registered_at' => $this->moment('last_registered_at'),
            'retired_at' => null === $this->getAttribute('retired_at') ? null : $this->moment('retired_at'),
            'retirement_reason' => $this->nullableText('retirement_reason'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->getAttribute('id'), 'endpoint_hash' => $this->getAttribute('endpoint_hash'), 'secrets' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_at' => 'immutable_datetime',
            'last_registered_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    private function text(string $column): string
    {
        $value = $this->getAttribute($column);

        return \is_string($value) ? $value : throw new \UnexpectedValueException(\sprintf('Cannot read the %s column of a web push subscription as a string.', $column));
    }

    private function nullableText(string $column): ?string
    {
        return null === $this->getAttribute($column) ? null : $this->text($column);
    }

    private function moment(string $column): \DateTimeImmutable
    {
        $value = $this->getAttribute($column);

        return $value instanceof \DateTimeImmutable ? $value : throw new \UnexpectedValueException(\sprintf('Cannot read the %s column of a web push subscription as a date.', $column));
    }
}
