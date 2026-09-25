<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Integration\Laravel;

use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use RomainMillan\WebPushNotification\Domain\Subscription\IdentifiedOwner;
use RomainMillan\WebPushNotification\Testing\TestBrowser;

final class CommandsTest extends LaravelTestCase
{
    private string|false $shellVerbosity = false;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.dist.xml silences consoles (SHELL_VERBOSITY=-1): these tests read the output.
        $this->shellVerbosity = getenv('SHELL_VERBOSITY');
        $this->changeShellVerbosity('0');
    }

    protected function tearDown(): void
    {
        $this->changeShellVerbosity(false === $this->shellVerbosity ? '' : $this->shellVerbosity);

        parent::tearDown();
    }

    #[Test]
    public function it_should_print_a_vapid_key_pair_with_a_warning(): void
    {
        $command = $this->command('web-push:vapid');

        $command->expectsOutputToContain('VAPID_PUBLIC_KEY=')
            ->expectsOutputToContain('VAPID_PRIVATE_KEY=')
            ->expectsOutputToContain('never commit it')
            ->assertSuccessful();
    }

    #[Test]
    public function it_should_send_a_test_notification_and_print_counts_but_no_endpoint(): void
    {
        $this->registerBrowser(IdentifiedOwner::fromSubscriberId('user:42'), TestBrowser::chrome('capability-token'));

        $command = $this->command('web-push:test', ['subscriber' => 'user:42']);

        $command->expectsTable(['Status', 'Subscriptions'], [['delivered', '1'], ['expired', '0'], ['transient', '0'], ['permanent', '0'], ['skipped', '0']])
            ->doesntExpectOutputToContain('capability-token')
            ->assertSuccessful();
    }

    #[Test]
    public function it_should_fail_the_test_command_for_a_subscriber_without_device(): void
    {
        $command = $this->command('web-push:test', ['subscriber' => 'user:404']);

        $command->expectsOutputToContain('no active subscription')->assertFailed();
    }

    #[Test]
    public function it_should_purge_and_print_the_counts(): void
    {
        $command = $this->command('web-push:purge');

        $command->expectsOutputToContain('Purged 0 retired and 0 abandoned anonymous')->assertSuccessful();
    }

    private function changeShellVerbosity(string $verbosity): void
    {
        putenv('SHELL_VERBOSITY='.$verbosity);
        $_ENV['SHELL_VERBOSITY'] = $verbosity;
        $_SERVER['SHELL_VERBOSITY'] = $verbosity;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function command(string $name, array $parameters = []): PendingCommand
    {
        $command = $this->artisan($name, $parameters);

        return $command instanceof PendingCommand ? $command : throw new \LogicException('There is no pending command to assert on.');
    }
}
