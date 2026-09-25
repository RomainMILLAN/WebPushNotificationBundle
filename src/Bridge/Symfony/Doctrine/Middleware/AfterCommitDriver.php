<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use RomainMillan\WebPushNotification\Bridge\Symfony\Doctrine\AfterCommitCallbacks;

/**
 * Inheritance imposed by DBAL's middleware extension point.
 */
final class AfterCommitDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $wrappedDriver,
        private readonly AfterCommitCallbacks $afterCommitCallbacks,
    ) {
        parent::__construct($wrappedDriver);
    }

    public function connect(#[\SensitiveParameter] array $params): Connection
    {
        return new AfterCommitConnection(parent::connect($params), $this->afterCommitCallbacks);
    }
}
