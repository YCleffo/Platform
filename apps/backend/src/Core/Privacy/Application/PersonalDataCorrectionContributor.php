<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

interface PersonalDataCorrectionContributor
{
    public function code(): string;

    /** @return non-empty-list<string> */
    public function supportedResourceTypes(): array;

    public function apply(CorrectionContext $context, CorrectionCommand $command): CorrectionResult;
}
