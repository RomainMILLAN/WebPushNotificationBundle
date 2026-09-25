<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Infrastructure\Network;

use function Symfony\Component\String\b;

/**
 * Only globally routable addresses (FILTER_FLAG_GLOBAL_RANGE, PHP 8.2): it also
 * refuses what NO_PRIV_RANGE | NO_RES_RANGE let through, such as 100.64.0.0/10
 * (carrier-grade NAT).
 */
final readonly class PublicIpPolicy
{
    public function isPublic(string $ip): bool
    {
        return false !== filter_var($this->normalize($ip), \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE);
    }

    /**
     * Collapses IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible (::a.b.c.d) IPv6
     * addresses to their embedded IPv4: filter_var's range flags do not unwrap
     * "::ffff:169.254.169.254", which would otherwise smuggle the metadata endpoint
     * past the check. Never weakens the check.
     */
    public function normalize(string $ip): string
    {
        $packed = @inet_pton($ip);

        if (false === $packed || 16 !== b($packed)->length()) {
            return $ip;
        }

        $head = b($packed)->slice(0, 12)->toString();

        if (str_repeat("\x00", 10)."\xff\xff" === $head || str_repeat("\x00", 12) === $head) {
            $ipv4 = @inet_ntop(b($packed)->slice(12)->toString());

            return false !== $ipv4 ? $ipv4 : $ip;
        }

        return $ip;
    }
}
