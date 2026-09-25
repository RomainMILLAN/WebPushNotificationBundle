<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Bridge\Symfony\Signing;

use Psr\Clock\ClockInterface;
use RomainMillan\WebPushNotification\Application\Port\ActionUrlSigner;
use RomainMillan\WebPushNotification\Domain\Message\ActionUrl;
use RomainMillan\WebPushNotification\Domain\Message\Origin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signs the URL of a PostAction route with the framework UriSigner. The receiving
 * controller MUST call verify(): it checks the signature and the expiry on every
 * supported Symfony version. Symfony 7.1+ signs the expiry natively (_expiration);
 * 6.4 has no expiry, so it travels as a signed _web_push_expires query parameter.
 */
final readonly class SymfonyActionUrlSigner implements ActionUrlSigner
{
    private const NATIVE_EXPIRATION_PARAMETER = '_expiration';
    private const LEGACY_EXPIRATION_PARAMETER = '_web_push_expires';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
        private Origin $origin,
        private ClockInterface $clock,
    ) {
    }

    public function sign(string $route, array $parameters, \DateInterval $validity): ActionUrl
    {
        $expiresAt = $this->clock->now()->add($validity)->getTimestamp();

        if ($this->supportsNativeExpiration()) {
            $url = $this->uriSigner->sign($this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL), $expiresAt);
        } else {
            $url = $this->uriSigner->sign($this->urlGenerator->generate($route, $parameters + [self::LEGACY_EXPIRATION_PARAMETER => $expiresAt], UrlGeneratorInterface::ABSOLUTE_URL));
        }

        return ActionUrl::fromString($url, $this->origin);
    }

    /** Signature untouched and not expired — an URL signed without expiry is refused. */
    public function verify(Request $request): bool
    {
        if (!$this->uriSigner->checkRequest($request)) {
            return false;
        }

        $expiresAt = $request->query->get($this->supportsNativeExpiration() ? self::NATIVE_EXPIRATION_PARAMETER : self::LEGACY_EXPIRATION_PARAMETER);

        return is_numeric($expiresAt) && $this->clock->now()->getTimestamp() < (int) $expiresAt;
    }

    /** Symfony 7.1 added the $expirationParameter constructor argument together with sign($uri, $expiration). */
    private function supportsNativeExpiration(): bool
    {
        return (new \ReflectionMethod(UriSigner::class, '__construct'))->getNumberOfParameters() >= 3;
    }
}
