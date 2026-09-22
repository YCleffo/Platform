<?php

declare(strict_types=1);

namespace App\Core\Module\Application;

final readonly class ModuleStateChange
{
    public function __construct(
        public ModuleView $before,
        public ModuleView $after,
        public bool $changed,
    ) {}
}
