<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Tests\Support;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\HttpStatusClassifier;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\MinishlinkPushTransport;
use RomainMillan\WebPushNotification\Infrastructure\Minishlink\WebPushClientFactory;
use RomainMillan\WebPushNotification\Infrastructure\Network\HostPinning;
use RomainMillan\WebPushNotification\Infrastructure\Network\NoPinning;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidCredentials;

/**
 * The real Minishlink stack (encryption, VAPID signing) in front of a Guzzle
 * MockHandler instead of the network.
 */
final class TransportHarness
{
    public readonly MockHandler $responses;
    public readonly SpyLogger $logger;
    public readonly MinishlinkPushTransport $transport;

    /** @var array<mixed> filled by Guzzle's history middleware (by reference) */
    private array $sent = [];

    public function __construct(HostPinning $hostPinning = new NoPinning())
    {
        $this->responses = new MockHandler();
        $stack = HandlerStack::create($this->responses);
        // @phpstan-ignore assign.propertyType (Guzzle types its by-reference container as array|ArrayAccess)
        $stack->push(Middleware::history($this->sent));
        $this->logger = new SpyLogger();

        $this->transport = new MinishlinkPushTransport(
            WebPushClientFactory::createWithHandlerStack(self::vapid(), 15, $stack),
            new HttpStatusClassifier(),
            $hostPinning,
            $this->logger,
        );
    }

    public static function vapid(): VapidCredentials
    {
        return VapidCredentials::fromKeys(VapidKeys::pair()['publicKey'], VapidKeys::pair()['privateKey'], 'mailto:ops@example.com');
    }

    /** The n-th request sent to the (mocked) push service. */
    public function sentRequest(int $index): RequestInterface
    {
        $entry = $this->sent[$index] ?? null;
        $request = \is_array($entry) ? ($entry['request'] ?? null) : null;
        \assert($request instanceof RequestInterface);

        return $request;
    }

    public function sentCount(): int
    {
        return \count($this->sent);
    }
}
