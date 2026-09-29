<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use App\Core\Privacy\Domain\RetentionCandidate;
use DomainException;

final readonly class RetentionDependencyContributorRegistry
{
    private array $contributors;

    /** @param iterable<RetentionDependencyContributor> $contributors */
    public function __construct(iterable $contributors = [])
    {
        $byCode = [];
        foreach ($contributors as $contributor) {
            MachineCode::assert($contributor->code());
            if (isset($byCode[$contributor->code()])) {
                throw new DomainException('Duplicate retention dependency contributor.');
            }
            $byCode[$contributor->code()] = $contributor;
        }
        $this->contributors = $byCode;
    }

    public function resolved(RetentionCandidate $candidate): bool
    {
        foreach ($this->contributors as $contributor) {
            if ($contributor->supports($candidate->resourceType)) {
                try {
                    if (!$contributor->resolved($candidate)) {
                        return false;
                    }
                } catch (\Throwable) {
                    return false;
                }
            }
        }
        return true;
    }
}
