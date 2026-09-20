<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

interface PersonRepository
{
    /**
     * @return list<PersonView>
     */
    public function findAll(): array;
}
