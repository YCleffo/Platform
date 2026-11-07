<?php

declare(strict_types=1);

namespace App\Storage\Application;

interface Mutation
{
    /**
     * @param callable():array<string,mixed> $action
     * @return array<string,mixed>
     */
    public function run(StorageScope $scope, string $operation, callable $action): array;
}
