<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class ListPersonContacts
{
    public function __construct(
        private PersonRepository $persons,
        private PersonContactRepository $contacts,
    ) {}

    /**
     * @return list<PersonContactView>|null
     */
    public function execute(string $personId): ?array
    {
        if ($this->persons->findById($personId) === null) {
            return null;
        }

        return $this->contacts->findByPersonId($personId);
    }
}
