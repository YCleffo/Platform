<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CrossBorderTransferDefinition
{
    /** @param list<string> $dataCategories */
    public function __construct(public string $providerCode, public string $country,
        public string $purposeCode, public array $dataCategories,
        public string $notificationStatus, public bool $allowed,
        public ?string $evidenceReference, public DateTimeImmutable $activeFrom,
        public ?DateTimeImmutable $activeUntil = null)
    {
        MachineCode::assert($providerCode);
        MachineCode::assert($purposeCode);
        foreach ($dataCategories as $category) { MachineCode::assert($category); }
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1 || $dataCategories === []
            || !in_array($notificationStatus, ['unknown', 'pending', 'notified', 'not_required'], true)
            || ($allowed && $notificationStatus === 'notified' && trim((string) $evidenceReference) === '')
            || ($activeUntil !== null && $activeUntil <= $activeFrom)) {
            throw new InvalidArgumentException('Invalid cross-border transfer definition.');
        }
    }

    public function activeAt(DateTimeImmutable $at): bool
    {
        return $this->activeFrom <= $at && ($this->activeUntil === null || $at < $this->activeUntil);
    }
}
