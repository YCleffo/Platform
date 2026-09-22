<?php

declare(strict_types=1);

namespace App\Core\Settings\Application;

final readonly class FeatureEvaluation
{
    /** @param array<array-key, mixed> $config */
    public function __construct(
        public bool $enabled,
        public array $config,
    ) {}
}
