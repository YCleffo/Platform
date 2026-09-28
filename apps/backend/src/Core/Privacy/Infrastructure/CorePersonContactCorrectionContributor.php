<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Person\Application\UpdatePersonContact;
use App\Core\Privacy\Application\CorrectionCommand;
use App\Core\Privacy\Application\CorrectionContext;
use App\Core\Privacy\Application\CorrectionResult;
use App\Core\Privacy\Application\PersonalDataCorrectionContributor;
use DomainException;

final readonly class CorePersonContactCorrectionContributor implements PersonalDataCorrectionContributor
{
    public function __construct(private UpdatePersonContact $contacts) {}

    public function code(): string { return 'core.person_contact'; }

    public function supportedResourceTypes(): array { return ['core.person_contact']; }

    public function apply(CorrectionContext $context, CorrectionCommand $command): CorrectionResult
    {
        if ($command->contributorCode !== $this->code()
            || $this->contacts->execute($context->subjectPersonId, $command->resourceId,
                $command->changes) === null) {
            throw new DomainException('Contact cannot be corrected for subject.');
        }
        return new CorrectionResult('core.person_contact', $command->resourceId,
            array_map(static fn(string $field): string => strtolower((string) preg_replace('/[A-Z]/', '_$0', $field)),
                array_keys($command->changes)));
    }
}
