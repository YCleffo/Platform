<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Person\Api\PersonInput;
use App\Core\Person\Application\UpdatePerson;
use App\Core\Privacy\Application\CorrectionCommand;
use App\Core\Privacy\Application\CorrectionContext;
use App\Core\Privacy\Application\CorrectionResult;
use App\Core\Privacy\Application\PersonalDataCorrectionContributor;
use DomainException;
use Yiisoft\Input\Http\InputValidationException;

final readonly class CorePersonCorrectionContributor implements PersonalDataCorrectionContributor
{
    public function __construct(private UpdatePerson $persons) {}

    public function code(): string
    {
        return 'core.person';
    }

    public function supportedResourceTypes(): array
    {
        return ['core.person'];
    }

    public function apply(CorrectionContext $context, CorrectionCommand $command): CorrectionResult
    {
        if ($command->contributorCode !== $this->code() || $command->resourceId !== $context->subjectPersonId) {
            throw new DomainException('Correction resource is outside subject.');
        }
        try {
            $update = PersonInput::updatePerson($command->resourceId, $command->changes);
        } catch (InputValidationException) {
            throw new DomainException('Invalid person correction.');
        }
        if ($this->persons->execute($update) === null) {
            throw new DomainException('Person cannot be corrected.');
        }
        $paths = [];
        foreach (array_keys($command->changes) as $field) {
            $paths[] = strtolower((string) preg_replace('/[A-Z]/', '_$0', (string) $field));
        }
        return new CorrectionResult('core.person', $command->resourceId, $paths);
    }
}
