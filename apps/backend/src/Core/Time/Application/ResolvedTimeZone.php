<?php

declare(strict_types=1);

namespace App\Core\Time\Application;

use App\Core\Time\Domain\TimeZone;
use InvalidArgumentException;

final readonly class ResolvedTimeZone
{
    public const USER = 'user';
    public const CLIENT = 'client';
    public const GEOIP = 'geoip';
    public const DEFAULT = 'default';

    public function __construct(public TimeZone $timezone, public string $source)
    {
        if (!in_array($source, [self::USER, self::CLIENT, self::GEOIP, self::DEFAULT], true)) {
            throw new InvalidArgumentException('Timezone resolution source is invalid.');
        }
    }
}
