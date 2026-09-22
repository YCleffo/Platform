<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

use DateTimeImmutable;

/** @internal Security-sensitive state must never use the ordinary account presenter. */
final readonly class AccountSecurityState
{
    public function __construct(
        public string $accountId,
        public ?string $personId,
        public string $accountType,
        public string $status,
        public string $securityStamp,
        public ?string $lockedUntil,
        public ?string $locale,
        public ?string $timezone,
        public ?string $disabledAt,
        public ?string $archivedAt,
    ) {}

    public function allowsAuthentication(DateTimeImmutable $now): bool
    {
        return $this->status === 'active'
            && $this->disabledAt === null
            && $this->archivedAt === null
            && ($this->lockedUntil === null || new DateTimeImmutable($this->lockedUntil) <= $now);
    }
}
