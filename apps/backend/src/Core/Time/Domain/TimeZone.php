<?php

declare(strict_types=1);

namespace App\Core\Time\Domain;

use DateTimeZone;
use InvalidArgumentException;

final readonly class TimeZone
{
    /** @var non-empty-string */
    public string $identifier;

    public function __construct(string $identifier)
    {
        if (
            $identifier === ''
            || strlen($identifier) > 64
            || preg_match('/\A(?:UTC|GMT)[+-]\d/i', $identifier) === 1
            || str_starts_with($identifier, 'Etc/GMT')
            || !in_array($identifier, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
        ) {
            throw new InvalidArgumentException('Timezone must be a supported IANA identifier.');
        }

        $this->identifier = $identifier;
    }

    public function native(): DateTimeZone
    {
        return new DateTimeZone($this->identifier);
    }
}
