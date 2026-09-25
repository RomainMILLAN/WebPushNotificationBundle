<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\AfterCommitCallbacks;

/** Registered on the configured DBAL connection only (tag doctrine.middleware). */
final readonly class AfterCommitMiddleware implements Middleware
{
    public function __construct(
        private AfterCommitCallbacks $afterCommitCallbacks,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new AfterCommitDriver($driver, $this->afterCommitCallbacks);
    }
}
