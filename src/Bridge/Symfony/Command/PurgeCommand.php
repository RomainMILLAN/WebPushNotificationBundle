<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Command;

use RomainMillan\WebPushNotification\Application\PurgeSubscriptions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Storage limitation: schedule it daily. Inheritance imposed by the Console component.
 */
final class PurgeCommand extends Command
{
    public function __construct(
        private readonly PurgeSubscriptions $purgeSubscriptions,
    ) {
        parent::__construct('webpush:purge');
    }

    protected function configure(): void
    {
        $this->setDescription('Delete long-retired and abandoned anonymous web push subscriptions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purged = $this->purgeSubscriptions->purge();

        (new SymfonyStyle($input, $output))->success(\sprintf('Deleted %d retired and %d abandoned anonymous subscriptions.', $purged->retired, $purged->abandonedAnonymous));

        return Command::SUCCESS;
    }
}
