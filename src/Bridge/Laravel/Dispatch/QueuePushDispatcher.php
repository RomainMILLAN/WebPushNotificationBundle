<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Dispatch;

use Illuminate\Contracts\Bus\Dispatcher;
use RomainMillan\WebPushNotification\Application\Port\PushDispatcher;
use RomainMillan\WebPushNotification\Application\Queue\QueuedDeliveryPlanner;
use RomainMillan\WebPushNotification\Bridge\Laravel\Jobs\SendWebPushJob;
use RomainMillan\WebPushNotification\Domain\Delivery\Audience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

/**
 * delivery.dispatcher: queue — one SendWebPushJob per subscription, dispatched after
 * the current database transaction commits (a rolled back notification is never
 * sent).
 */
final readonly class QueuePushDispatcher implements PushDispatcher
{
    /**
     * @param string $connection '' for the default queue connection
     * @param string $queue      '' for the connection's default queue
     */
    public function __construct(
        private QueuedDeliveryPlanner $queuedDeliveryPlanner,
        private Dispatcher $dispatcher,
        private string $connection = '',
        private string $queue = '',
    ) {
    }

    public function dispatch(Audience $audience, WebPushMessage $message, DeliveryOptions $options): void
    {
        foreach ($this->queuedDeliveryPlanner->plan($audience, $message, $options) as $delivery) {
            $job = (new SendWebPushJob($delivery->toArray()))->afterCommit();

            if ('' !== $this->connection) {
                $job->onConnection($this->connection);
            }

            if ('' !== $this->queue) {
                $job->onQueue($this->queue);
            }

            $this->dispatcher->dispatch($job);
        }
    }
}
