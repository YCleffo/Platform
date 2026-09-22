<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class CreatePerson
{
    public function __construct(
        private PersonRepository $repository,
    ) {}

    public function execute(CreatePersonCommand $command): PersonView
    {
        return $this->repository->create($command);
    }
}
