<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CrossBorderTransferDefinition
{
    /** @param list<string> $dataCategories */
    public function __construct(
        public string $providerCode,
        public string $country,
        public string $purposeCode,
        public array $dataCategories,
        public string $notificationStatus,
        public bool $allowed,
        public ?string $evidenceReference,
        public DateTimeImmutable $activeFrom,
        public ?DateTimeImmutable $activeUntil = null,
        public ?string $adequacyStatus = null,
        public ?DateTimeImmutable $notificationSubmittedAt = null,
        public ?string $notificationEvidenceReference = null,
        public ?DateTimeImmutable $regulatorReviewUntil = null,
        public string $regulatorDecision = 'pending',
    ) {
        MachineCode::assert($providerCode);
        MachineCode::assert($purposeCode);
        foreach ($dataCategories as $category) {
            MachineCode::assert($category);
        }
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1 || $dataCategories === []
            || !in_array($notificationStatus, ['unknown', 'pending', 'notified', 'not_required'], true)
            || ($allowed && $notificationStatus === 'notified' && trim((string) $evidenceReference) === '')
            || ($activeUntil !== null && $activeUntil <= $activeFrom)) {
            throw new InvalidArgumentException('Invalid cross-border transfer definition.');
        }
        if (($adequacyStatus !== null && !in_array($adequacyStatus, ['adequate', 'non_adequate'], true))
            || !in_array($regulatorDecision, ['pending', 'no_restriction', 'restricted', 'prohibited'], true)) {
            throw new InvalidArgumentException('Invalid cross-border regulator decision.');
        }
    }

    public function activeAt(DateTimeImmutable $at): bool
    {
        return $this->activeFrom <= $at && ($this->activeUntil === null || $at < $this->activeUntil);
    }

    public function permits(DateTimeImmutable $at): bool
    {
        if (!$this->allowed || !$this->activeAt($at)
            || $this->adequacyStatus === null || $this->notificationSubmittedAt === null
            || $this->notificationSubmittedAt > $at
            || trim((string) $this->notificationEvidenceReference) === ''
            || in_array($this->regulatorDecision, ['restricted', 'prohibited'], true)) {
            return false;
        }
        if ($this->adequacyStatus === 'non_adequate'
            && ($this->regulatorReviewUntil === null || $at < $this->regulatorReviewUntil)) {
            return false;
        }
        return true;
    }
}
