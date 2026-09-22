<?php

declare(strict_types=1);

namespace App\Core\Module\Application;

interface ModuleRepository
{
    /** @return list<ModuleView> */
    public function findAll(): array;

    public function findByCode(string $code): ?ModuleView;

    public function setEnabled(string $code, bool $enabled): ?ModuleStateChange;
}
