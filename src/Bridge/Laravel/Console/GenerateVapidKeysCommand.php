<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Console;

use Illuminate\Console\Command;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidKeyGenerator;

/** Prints a key pair — and never writes it anywhere: where secrets live is the operator's call. */
final class GenerateVapidKeysCommand extends Command
{
    /** @var string */
    protected $signature = 'web-push:vapid';

    /** @var string */
    protected $description = 'Generate a VAPID key pair for web push notifications';

    public function handle(VapidKeyGenerator $vapidKeyGenerator): int
    {
        $keys = $vapidKeyGenerator->generate();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('The private key is a secret: store it in your secret manager or .env, never commit it.');
        $this->warn('It may now sit in your terminal scrollback, shell history or CI logs: clear them if they are shared.');
        $this->warn('Changing the key pair later invalidates every existing subscription (clients re-subscribe).');

        return self::SUCCESS;
    }
}
