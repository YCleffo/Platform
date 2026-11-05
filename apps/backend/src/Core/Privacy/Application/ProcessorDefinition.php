<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DateTimeImmutable;
use InvalidArgumentException;

readonly class ProcessorDefinition
{
    /**
     * @param list<string> $purposes
     * @param list<string> $dataCategories
     */
    public function __construct(
        public string $code,
        public string $nameReference,
        public string $role,
        public array $purposes,
        public array $dataCategories,
        public string $country,
        public string $contractReference,
        public bool $securityRequirementsConfirmed,
        public bool $localizationCompatible,
        public DateTimeImmutable $activeFrom,
        public ?DateTimeImmutable $activeUntil = null,
        public ?string $name = null,
        public ?string $address = null,
    ) {
        MachineCode::assert($code);
        foreach ([...$purposes, ...$dataCategories] as $value) {
            MachineCode::assert($value);
        }
        if (!in_array($role, ['operator', 'processor_by_instruction', 'recipient'], true)
            || $purposes === [] || $dataCategories === []
            || trim($nameReference) === '' || trim($contractReference) === ''
            || preg_match('/^[A-Z]{2}$/D', $country) !== 1
            || ($activeUntil !== null && $activeUntil <= $activeFrom)) {
            throw new InvalidArgumentException('Invalid processor definition.');
        }
    }

    public function activeAt(DateTimeImmutable $at): bool
    {
        return $this->activeFrom <= $at && ($this->activeUntil === null || $at < $this->activeUntil);
    }
}
