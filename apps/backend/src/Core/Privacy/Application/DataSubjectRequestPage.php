<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

final readonly class DataSubjectRequestPage
{
    /** @param list<DataSubjectRequestView> $items */
    public function __construct(public array $items, public int $limit, public int $offset, public int $total) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn(DataSubjectRequestView $request): array => $request->toArray(), $this->items),
            'pagination' => ['limit' => $this->limit, 'offset' => $this->offset, 'total' => $this->total],
        ];
    }
}
