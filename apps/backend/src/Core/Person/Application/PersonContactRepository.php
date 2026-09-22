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
}
