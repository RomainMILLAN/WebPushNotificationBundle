<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use Psr\Log\AbstractLogger;

final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** Everything logged, as one string — for "never contains the endpoint" assertions. */
    public function dump(): string
    {
        return json_encode($this->records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
