<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class ListPersons
{
    public function __construct(
        private PersonRepository $repository,
    ) {}

    /**
     * @return list<PersonView>
     */
    public function execute(): array
    {
        return $this->repository->findAll();
    }
}
