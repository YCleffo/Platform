<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use App\Core\Privacy\Domain\RetentionCandidate;
use DomainException;

final readonly class RetentionCandidateContributorRegistry
{
    /** @var array<string, RetentionCandidateContributor> */
    private array $contributors;

    /** @param iterable<RetentionCandidateContributor> $contributors */
    public function __construct(iterable $contributors = [])
    {
        $found = [];
        foreach ($contributors as $contributor) {
            MachineCode::assert($contributor->code());
            if (isset($found[$contributor->code()])) {
                throw new DomainException('Duplicate retention candidate contributor.');
            }
            $found[$contributor->code()] = $contributor;
        }
        $this->contributors = $found;
    }

    /** @return list<RetentionCandidate> */
    public function collect(int $limit, ?string $subjectPersonId = null): array
    {
        if ($limit < 1 || $limit > 1001) {
            throw new DomainException('Retention batch must be between 1 and 1001.');
        }
        $result = [];
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->candidates($limit - count($result), $subjectPersonId) as $candidate) {
                /** @var mixed $candidate */
                if (!$candidate instanceof RetentionCandidate || $candidate->contributorCode !== $contributor->code()
                    || !$contributor->supportsPurpose($candidate->purposeCode)
                    || ($subjectPersonId !== null && $candidate->subjectPersonId !== $subjectPersonId)) {
                    throw new DomainException('Invalid retention candidate contributor output.');
                }
                $result[] = $candidate;
                if (count($result) === $limit) {
                    return $result;
                }
            }
        }
        return $result;
    }

    public function available(): bool
    {
        return $this->contributors !== [];
    }

    public function coversResourcePurpose(string $resourceType, string $purposeCode): bool
    {
        foreach ($this->contributors as $contributor) {
            try {
                if (method_exists($contributor, 'resourceType')
                    && $contributor->resourceType() === $resourceType
                    && $contributor->supportsPurpose($purposeCode)) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }

    /** @param list<string> $purposeCodes */
    public function coversAll(array $purposeCodes): bool
    {
        foreach ($purposeCodes as $purposeCode) {
            $covered = false;
            foreach ($this->contributors as $contributor) {
                try {
                    $supports = $contributor->supportsPurpose($purposeCode);
                } catch (\Throwable) {
                    return false;
                }
                if ($supports) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }
        return true;
    }
}
