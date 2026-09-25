<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use RomainMillan\WebPushNotification\Application\Contract\EncodedPayload;
use RomainMillan\WebPushNotification\Application\Port\PushTransport;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryOutcome;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryReport;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryTarget;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;

final class RecordingPushTransport implements PushTransport
{
    /** @var list<DeliveryTarget> */
    public array $delivered = [];

    /** @var list<EncodedPayload> */
    public array $payloads = [];

    /** @var array<string, DeliveryOutcome> scripted outcome by subscription id */
    private array $scripted = [];

    public function answer(string $subscriptionId, DeliveryOutcome $outcome): void
    {
        $this->scripted[$subscriptionId] = $outcome;
    }

    public function deliver(array $targets, EncodedPayload $payload, DeliveryOptions $options): DeliveryReport
    {
        $this->payloads[] = $payload;
        $report = DeliveryReport::empty();

        foreach ($targets as $target) {
            $this->delivered[] = $target;
            $report = $report->recordOutcome($target->subscriptionId(), $this->scripted[$target->subscriptionId()->toString()] ?? DeliveryOutcome::delivered());
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    public function deliveredIds(): array
    {
        return array_map(static fn (DeliveryTarget $target): string => $target->subscriptionId()->toString(), $this->delivered);
    }
}
