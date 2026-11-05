<?php

declare(strict_types=1);

namespace App\Education\Application;

interface Mutation
{
    /**
     * @param callable():array<string,mixed> $action
     * @return array<string,mixed>
     */
    public function run(Scope $scope, string $operation, callable $action): array;
}
