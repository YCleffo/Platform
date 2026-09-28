<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DomainException;

final readonly class PersonalDataSection
{
    /**
     * @param list<string> $dataCategories
     * @param list<array<string, mixed>> $records
     */
    public function __construct(
        public string $contributorCode,
        public array $dataCategories,
        public array $records,
    ) {
        MachineCode::assert($contributorCode);
        if ($dataCategories === []) {
            throw new DomainException('Personal data section requires categories.');
        }
        foreach ($dataCategories as $category) {
            MachineCode::assert($category);
        }
    }

    /** @return array{contributorCode:string,dataCategories:list<string>,records:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return ['contributorCode' => $this->contributorCode,
            'dataCategories' => $this->dataCategories, 'records' => $this->records];
    }
}
