<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Symfony\App;

use RomainMillan\WebPushNotification\Application\Contract\EncodedPayload;
use RomainMillan\WebPushNotification\Application\Port\PushTransport;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryReport;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Tests\Support\RecordingPushTransport;

/** The push services, replaced by a recorder in the test container. */
final class RecordingTransport implements PushTransport
{
    public RecordingPushTransport $recorder;

    /** @var list<DeliveryOptions> */
    public array $options = [];

    public function __construct()
    {
        $this->recorder = new RecordingPushTransport();
    }

    public function deliver(array $targets, EncodedPayload $payload, DeliveryOptions $options): DeliveryReport
    {
        $this->options[] = $options;

        return $this->recorder->deliver($targets, $payload, $options);
    }
}
