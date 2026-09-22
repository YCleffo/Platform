<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

final readonly class AccountPage
{
    /**
     * @param list<AccountView> $items
     */
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
                static fn(AccountView $account): array => $account->toArray(),
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
