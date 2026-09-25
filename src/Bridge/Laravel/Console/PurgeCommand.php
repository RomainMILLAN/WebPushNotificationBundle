<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Laravel\Console;

use Illuminate\Console\Command;
use RomainMillan\WebPushNotification\Application\PurgeSubscriptions;

/** Storage limitation: schedule it daily ($schedule->command('web-push:purge')->daily()). */
final class PurgeCommand extends Command
{
    /** @var string */
    protected $signature = 'web-push:purge';

    /** @var string */
    protected $description = 'Delete retired web push subscriptions and stale anonymous ones';

    public function handle(PurgeSubscriptions $purgeSubscriptions): int
    {
        $purged = $purgeSubscriptions->purge();

        $this->info(\sprintf('Purged %d retired and %d abandoned anonymous web push subscriptions.', $purged->retired, $purged->abandonedAnonymous));

        return self::SUCCESS;
    }
}
