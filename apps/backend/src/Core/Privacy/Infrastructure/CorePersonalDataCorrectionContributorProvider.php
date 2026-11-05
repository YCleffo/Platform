<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Person\Application\UpdatePerson;
use App\Core\Person\Application\UpdatePersonContact;
use App\Core\Privacy\Application\PersonalDataCorrectionContributor;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, PersonalDataCorrectionContributor> */
final readonly class CorePersonalDataCorrectionContributorProvider implements IteratorAggregate
{
    /** @param iterable<PersonalDataCorrectionContributor> $moduleContributors */
    public function __construct(
        private UpdatePerson $persons,
        private UpdatePersonContact $contacts,
        private iterable $moduleContributors = [],
    ) {}

    /** @return Traversable<int, PersonalDataCorrectionContributor> */
    public function getIterator(): Traversable
    {
        yield new CorePersonCorrectionContributor($this->persons);
        yield new CorePersonContactCorrectionContributor($this->contacts);
        yield from $this->moduleContributors;
    }
}
