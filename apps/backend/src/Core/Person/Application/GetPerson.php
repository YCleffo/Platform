<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class GetPerson
{
    public function __construct(
        private PersonRepository $repository,
    ) {}

    public function execute(string $id): ?PersonView
    {
        return $this->repository->findById($id);
    }
}
