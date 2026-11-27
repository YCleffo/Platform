<?php

declare(strict_types=1);

namespace App\Workspace\Application;

interface DashboardProviderInterface
{
    public function code(): string;

    public function moduleCode(): ?string;

    public function isAvailable(DashboardContext $context): bool;

    /**
     * Returns module-scoped dashboard contributions.
     *
     * @return array{
     *     summary?: array<string, int|float|string|bool>,
     *     widgets?: list<array<string, mixed>>,
     *     quickActions?: list<array<string, mixed>>,
     *     recentActivity?: list<array<string, mixed>>
     * }
     */
    public function provide(DashboardContext $context): array;
}
