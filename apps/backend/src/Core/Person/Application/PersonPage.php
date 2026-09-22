<?php

declare(strict_types=1);

namespace App\Core\Person\Application;

final readonly class PersonPage
{
    /**
     * @param list<PersonView> $items
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
                static fn(PersonView $person): array => $person->toArray(),
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
