<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class ListPersons
{
    public function __construct(
        private PersonRepository $repository,
    ) {}

    public function execute(int $limit, int $offset): PersonPage
    {
        return $this->repository->findPage($limit, $offset);
    }
}
