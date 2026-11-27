<?php

declare(strict_types=1);

namespace App\Workspace\Application;

interface SearchProviderInterface
{
    /** @return list<string> */
    public function categories(): array;

    public function moduleCode(): ?string;

    public function isAvailable(SearchQuery $query): bool;

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     totalsByCategory: array<string, int>
     * }
     */
    public function search(SearchQuery $query): array;
}
