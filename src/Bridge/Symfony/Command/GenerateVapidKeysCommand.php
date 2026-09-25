<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Command;

use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidKeyGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a new VAPID key pair, never writes it anywhere. Inheritance imposed by the
 * Console component.
 */
final class GenerateVapidKeysCommand extends Command
{
    public function __construct(
        private readonly VapidKeyGenerator $vapidKeyGenerator,
    ) {
        parent::__construct('webpush:vapid:generate');
    }

    protected function configure(): void
    {
        $this->setDescription('Generate a VAPID key pair for web push');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keys = $this->vapidKeyGenerator->generate();

        $io->writeln('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $io->writeln('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $io->warning([
            'Store the private key as a secret (secrets:set, vault, environment) — never commit it.',
            'This output may remain in your shell history and CI logs: clear them if needed.',
            'Rotating the pair invalidates existing subscriptions until each browser comes back and re-subscribes.',
        ]);

        return Command::SUCCESS;
    }
}
