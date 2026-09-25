<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Console;

use Illuminate\Console\Command;
use RomainMillan\WebPushNotification\Application\WebPushSender;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryStatus;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;

/** Sends a test notification synchronously and prints counts per status — never an endpoint. */
final class SendTestCommand extends Command
{
    /** @var string */
    protected $signature = 'web-push:test {subscriber : The subscriber id, e.g. "user:42"}';

    /** @var string */
    protected $description = 'Send a test web push notification to every device of a subscriber';

    public function handle(WebPushSender $webPushSender, DeliveryOptions $deliveryOptions): int
    {
        $subscriber = $this->argument('subscriber');

        try {
            $audience = SubscriberAudience::fromSubscriberId(\is_string($subscriber) ? $subscriber : '');
        } catch (InvalidValue $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        $report = $webPushSender->send($audience, WebPushMessage::createWithTitle('Web push test', 'Notifications work on this device.'), $deliveryOptions);

        $this->table(['Status', 'Subscriptions'], array_map(
            static fn (DeliveryStatus $status): array => [$status->value, (string) $report->countWith($status)],
            DeliveryStatus::cases(),
        ));

        if (0 === \count($report)) {
            $this->warn('This subscriber has no active subscription.');
        }

        return 0 < $report->countWith(DeliveryStatus::Delivered) ? self::SUCCESS : self::FAILURE;
    }
}
