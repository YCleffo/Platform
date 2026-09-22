<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

interface PersonRepository
{
    public function findPage(int $limit, int $offset): PersonPage;

    public function findById(string $id): ?PersonView;

    public function create(CreatePersonCommand $command): PersonView;

    public function update(UpdatePersonCommand $command): ?PersonView;

    public function archive(string $id): ?PersonView;
}
