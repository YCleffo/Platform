<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

interface PersonContactRepository
{
    /**
     * @return list<PersonContactView>
     */
    public function findByPersonId(string $personId): array;

    public function create(CreatePersonContactCommand $command, string $normalizedValue): PersonContactView;

    public function updateOwned(string $personId, string $contactId, ?string $value,
        ?string $normalizedValue, bool $valueProvided, ?string $label, bool $labelProvided,
        ?bool $isPrimary): ?PersonContactView;
}
