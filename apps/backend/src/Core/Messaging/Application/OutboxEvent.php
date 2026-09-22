<?php

declare(strict_types=1);

namespace App\Core\Messaging\Application;

final readonly class OutboxEvent
{
    /**
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $headers
     */
    public function __construct(
        public int $id,
        public string $eventId,
        public string $producerModule,
        public string $eventType,
        public ?string $aggregateType,
        public ?string $aggregateId,
        public array $payload,
        public array $headers,
        public ?string $correlationId,
        public ?string $causationId,
        public string $status,
        public int $attempts,
        public string $occurredAt,
        public string $availableAt,
        public ?string $publishedAt,
        public ?string $lockedBy,
        public ?string $lockedUntil,
        public ?string $lastError,
    ) {}
}
