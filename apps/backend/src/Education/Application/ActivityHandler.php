<?php

declare(strict_types=1);

namespace App\Education\Application;

/** Each implemented activity owns its input, private exercise and result verification. */
interface ActivityHandler
{
    public function validateConfig(array $config): array;
    public function createExercise(array $config, ?array $set = null): array;
    public function publicExercise(array $attempt): array;
    public function validateState(array $attempt, array $state): array;
    /** Returns a canonical request fingerprint and server calculated metrics. */
    public function evaluate(array $attempt, array $input): array;
}
