<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Minishlink;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use Minishlink\WebPush\WebPush;
use RomainMillan\WebPushNotification\Infrastructure\Vapid\VapidCredentials;

/**
 * Builds one WebPush client per batch — its client options carry that batch's
 * CURLOPT_RESOLVE pins, and Minishlink only reads client options at construction.
 *
 * The cURL handler is IMPOSED: Guzzle's "curl" request option (where the pins live)
 * is silently ignored by the stream handler, and the DNS-rebinding protection would
 * vanish without a sound. ext-curl is therefore a hard requirement of the package.
 */
final readonly class WebPushClientFactory
{
    private function __construct(
        private VapidCredentials $vapidCredentials,
        private int $timeoutSeconds,
        private HandlerStack $handlerStack,
    ) {
    }

    public static function createWithCurl(VapidCredentials $vapidCredentials, int $timeoutSeconds): self
    {
        return new self($vapidCredentials, $timeoutSeconds, HandlerStack::create(new CurlMultiHandler()));
    }

    /**
     * @internal tests only: a MockHandler stack instead of the network
     */
    public static function createWithHandlerStack(VapidCredentials $vapidCredentials, int $timeoutSeconds, HandlerStack $handlerStack): self
    {
        return new self($vapidCredentials, $timeoutSeconds, $handlerStack);
    }

    /**
     * @param list<string> $resolveEntries
     */
    public function createClient(array $resolveEntries): WebPush
    {
        $client = new WebPush($this->vapidCredentials->toWebPushAuth(), [], $this->timeoutSeconds, $this->clientOptions($resolveEntries));
        $client->setReuseVAPIDHeaders(true);
        // Kept on purpose (Minishlink's default): every payload is padded to the same
        // size, so the encrypted length leaks nothing about the content.
        $client->setAutomaticPadding(true);

        return $client;
    }

    /**
     * @param list<string> $resolveEntries
     *
     * @return array<string, mixed>
     */
    public function clientOptions(array $resolveEntries): array
    {
        $curl = [
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTPS,
        ];

        if ([] !== $resolveEntries) {
            $curl[\CURLOPT_RESOLVE] = $resolveEntries;
        }

        return [
            'handler' => $this->handlerStack,
            'allow_redirects' => false,
            'verify' => true,
            'timeout' => $this->timeoutSeconds,
            'connect_timeout' => min(5, $this->timeoutSeconds),
            'curl' => $curl,
        ];
    }
}
