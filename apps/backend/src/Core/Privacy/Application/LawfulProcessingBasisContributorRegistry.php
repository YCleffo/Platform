<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DomainException;

final readonly class LawfulProcessingBasisContributorRegistry
{
    /** @var array<string, LawfulProcessingBasisContributor> */
    private array $contributors;

    /** @param iterable<LawfulProcessingBasisContributor> $contributors */
    public function __construct(iterable $contributors = [])
    {
        $found = [];
        foreach ($contributors as $contributor) {
            MachineCode::assert($contributor->code());
            if (isset($found[$contributor->code()])) {
                throw new DomainException('Duplicate lawful basis contributor.');
            }
            $found[$contributor->code()] = $contributor;
        }
        $this->contributors = $found;
    }

    public function resolve(\App\Core\Privacy\Domain\RetentionCandidate $candidate): string
    {
        $found = false;
        $active = false;
        foreach ($this->contributors as $contributor) {
            if (!$contributor->supports($candidate->purposeCode, $candidate->resourceType)) {
                continue;
            }
            $found = true;
            try {
                $result = $contributor->resolve($candidate);
            } catch (\Throwable) {
                return 'unknown';
            }
            if (!in_array($result, ['active', 'inactive', 'unknown'], true) || $result === 'unknown') {
                return 'unknown';
            }
            $active = $active || $result === 'active';
        }
        return !$found ? 'unknown' : ($active ? 'active' : 'inactive');
    }

    public function hasSupporting(\App\Core\Privacy\Domain\RetentionCandidate $candidate): bool
    {
        foreach ($this->contributors as $contributor) {
            if ($contributor->supports($candidate->purposeCode, $candidate->resourceType)) {
                return true;
            }
        }
        return false;
    }

    public function supports(string $purposeCode, string $resourceType): bool
    {
        foreach ($this->contributors as $contributor) {
            if ($contributor->supports($purposeCode, $resourceType)) { return true; }
        }
        return false;
    }
}
