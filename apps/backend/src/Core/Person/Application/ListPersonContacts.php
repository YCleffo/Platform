<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

use App\Core\Privacy\Application\PrivacyResourceUsePolicy;

final readonly class ListPersonContacts
{
    public function __construct(
        private PersonRepository $persons,
        private PersonContactRepository $contacts,
        private PrivacyResourceUsePolicy $privacy,
    ) {}

    /**
     * @return list<PersonContactView>|null
     */
    public function execute(string $personId): ?array
    {
        if ($this->persons->findById($personId) === null) {
            return null;
        }

        return array_values(array_filter(
            $this->contacts->findByPersonId($personId),
            fn(PersonContactView $contact): bool => $this->privacy->allowed(
                $personId,
                'core.person_contact',
                $contact->id,
                'identity',
            ),
        ));
    }
}
