<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

final readonly class RolePage
{
    /** @param list<RoleView> $items */
    public function __construct(
        public array $items,
        public int $limit,
        public int $offset,
        public int $total,
    ) {}

    public function toArray(): array
    {
        return [
            'items' => array_map(static fn(RoleView $role): array => $role->toArray(), $this->items),
            'pagination' => [
                'limit' => $this->limit,
                'offset' => $this->offset,
                'total' => $this->total,
            ],
        ];
    }
}
