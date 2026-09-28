<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

interface PersonalDataContributor
{
    public function code(): string;

    /** @return non-empty-list<string> */
    public function categories(): array;

    public function collect(PersonalDataExportContext $context): PersonalDataSection;
}
