<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }

    /**
     * @return list<class-string>
     */
    public function dispatchedClasses(): array
    {
        return array_map(static fn (object $event): string => $event::class, $this->dispatched);
    }
}
