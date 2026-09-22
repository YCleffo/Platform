<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

final readonly class PermissionPage
{
    /** @param list<PermissionView> $items */
    public function __construct(
        public array $items,
        public int $limit,
        public int $offset,
        public int $total,
    ) {}

    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn(PermissionView $permission): array => $permission->toArray(),
                $this->items,
            ),
            'pagination' => [
                'limit' => $this->limit,
                'offset' => $this->offset,
                'total' => $this->total,
            ],
        ];
    }
}
