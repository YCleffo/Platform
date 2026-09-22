<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class UpdatePerson
{
    public function __construct(
        private PersonRepository $repository,
    ) {}

    public function execute(UpdatePersonCommand $command): ?PersonView
    {
        return $this->repository->update($command);
    }
}
