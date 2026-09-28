<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DomainException;

final readonly class PersonalDataCorrectionContributorRegistry
{
    /** @var array<string, PersonalDataCorrectionContributor> */
    private array $contributors;

    /** @param iterable<PersonalDataCorrectionContributor> $contributors */
    public function __construct(iterable $contributors)
    {
        $byCode = [];
        foreach ($contributors as $contributor) {
            $code = $contributor->code();
            MachineCode::assert($code);
            if (isset($byCode[$code])) {
                throw new DomainException('Duplicate correction contributor.');
            }
            foreach ($contributor->supportedResourceTypes() as $type) {
                MachineCode::assert($type);
            }
            $byCode[$code] = $contributor;
        }
        ksort($byCode, SORT_STRING);
        $this->contributors = $byCode;
    }

    public function get(string $code): PersonalDataCorrectionContributor
    {
        return $this->contributors[$code] ?? throw new DomainException('Unknown correction contributor.');
    }

    public function available(): bool { return $this->contributors !== []; }
}
