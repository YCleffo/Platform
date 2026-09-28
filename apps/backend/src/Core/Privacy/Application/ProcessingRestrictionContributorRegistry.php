<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DomainException;

final readonly class ProcessingRestrictionContributorRegistry
{
    /** @var array<string, ProcessingRestrictionContributor> */
    private array $contributors;

    /** @param iterable<ProcessingRestrictionContributor> $contributors */
    public function __construct(iterable $contributors = [])
    {
        $byCode = [];
        foreach ($contributors as $contributor) {
            $code = $contributor->code();
            MachineCode::assert($code);
            if (isset($byCode[$code])) {
                throw new DomainException('Duplicate restriction contributor.');
            }
            $byCode[$code] = $contributor;
        }
        ksort($byCode, SORT_STRING);
        $this->contributors = $byCode;
    }

    /** @return list<ProcessingRestrictionContributor> */
    public function forScope(string $scopeType, ?string $purposeCode, ?string $resourceType): array
    {
        return array_values(array_filter($this->contributors,
            static fn(ProcessingRestrictionContributor $contributor): bool =>
                $contributor->supports($scopeType, $purposeCode, $resourceType)));
    }
}
