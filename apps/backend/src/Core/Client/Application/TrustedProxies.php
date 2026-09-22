<?php

declare(strict_types=1);

namespace App\Core\Client\Application;

use App\Core\Network\IpRangeMatcher;

final readonly class TrustedProxies
{
    /** @param list<string> $ranges */
    public function __construct(private array $ranges)
    {
        foreach ($ranges as $range) {
            IpRangeMatcher::parse($range);
        }
    }

    public function contains(string $address): bool
    {
        foreach ($this->ranges as $range) {
            if (IpRangeMatcher::matches($address, $range)) {
                return true;
            }
        }

        return false;
    }
}
