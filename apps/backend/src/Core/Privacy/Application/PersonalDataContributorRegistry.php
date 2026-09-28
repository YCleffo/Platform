<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\MachineCode;
use DomainException;

final readonly class PersonalDataContributorRegistry
{
    /** @var list<PersonalDataContributor> */
    private array $contributors;

    /** @param iterable<PersonalDataContributor> $contributors */
    public function __construct(iterable $contributors)
    {
        $byCode = [];
        foreach ($contributors as $contributor) {
            $code = $contributor->code();
            MachineCode::assert($code);
            if (isset($byCode[$code])) {
                throw new DomainException('Duplicate personal data contributor.');
            }
            $byCode[$code] = $contributor;
        }
        ksort($byCode, SORT_STRING);
        $this->contributors = array_values($byCode);
    }

    /** @return list<PersonalDataContributor> */
    public function all(): array
    {
        return $this->contributors;
    }
}
