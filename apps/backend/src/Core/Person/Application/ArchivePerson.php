<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class ArchivePerson
{
    public function __construct(
        private PersonRepository $repository,
    ) {}

    public function execute(string $id): ?PersonView
    {
        return $this->repository->archive($id);
    }
}
