<?php

declare(strict_types=1);

namespace RomainMillan\WebPushNotification\Application;

use RomainMillan\WebPushNotification\Domain\Exception\InvalidValue;

/** How long retired and stale anonymous subscriptions are kept. */
final readonly class PurgeWindows
{
    private function __construct(
        private \DateInterval $retiredAfter,
        private \DateInterval $anonymousStaleAfter,
    ) {
    }

    /**
     * @param string $retiredAfter        e.g. "30 days"
     * @param string $anonymousStaleAfter e.g. "90 days"
     */
    public static function fromDurations(string $retiredAfter, string $anonymousStaleAfter): self
    {
        return new self(self::interval($retiredAfter), self::interval($anonymousStaleAfter));
    }

    public function retiredCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub($this->retiredAfter);
    }

    public function anonymousCutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub($this->anonymousStaleAfter);
    }

    private static function interval(string $duration): \DateInterval
    {
        try {
            // PHP 8.2 warns and returns false on garbage, 8.3+ throws: both end here.
            $interval = @\DateInterval::createFromDateString($duration);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (PHP 8.3+ throws DateMalformedIntervalStringException)
            $interval = false;
        }

        if (!$interval instanceof \DateInterval || (new \DateTimeImmutable('@0'))->add($interval) <= new \DateTimeImmutable('@0')) {
            throw InvalidValue::because('Cannot accept a purge duration that is not a positive relative duration such as "30 days".');
        }

        return $interval;
    }
}
