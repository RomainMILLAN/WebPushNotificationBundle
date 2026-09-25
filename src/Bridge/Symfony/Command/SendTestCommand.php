<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Command;

use RomainMillan\WebPushNotification\Application\WebPushSender;
use RomainMillan\WebPushNotification\Domain\Delivery\DeliveryStatus;
use RomainMillan\WebPushNotification\Domain\Delivery\SubscriberAudience;
use RomainMillan\WebPushNotification\Domain\Message\DeliveryOptions;
use RomainMillan\WebPushNotification\Domain\Message\WebPushMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends a test notification synchronously and prints the outcome counts — never an
 * endpoint. Inheritance imposed by the Console component.
 */
final class SendTestCommand extends Command
{
    public function __construct(
        private readonly WebPushSender $webPushSender,
        private readonly DeliveryOptions $deliveryOptions,
    ) {
        parent::__construct('webpush:test');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Send a test web push notification to every device of a subscriber')
            ->addArgument('subscriber', InputArgument::REQUIRED, 'The subscriber id (e.g. user:42)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $subscriber = $input->getArgument('subscriber');
        \assert(\is_string($subscriber));

        $report = $this->webPushSender->send(
            SubscriberAudience::fromSubscriberId($subscriber),
            WebPushMessage::createWithTitle('Test notification', 'Web push works on this device.'),
            $this->deliveryOptions->withTtl(60),
        );

        $io->table(['Outcome', 'Devices'], array_map(
            static fn (DeliveryStatus $status): array => [$status->value, (string) $report->countWith($status)],
            DeliveryStatus::cases(),
        ));

        return 0 === \count($report) ? Command::FAILURE : Command::SUCCESS;
    }
}
