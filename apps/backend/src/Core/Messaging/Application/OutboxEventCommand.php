<?php

declare(strict_types=1);

namespace App\Core\Messaging\Application;

final readonly class OutboxEventCommand
{
    /**
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $headers
     */
    public function __construct(
        public string $producerModule,
        public string $eventType,
        public array $payload,
        public array $headers = [],
        public ?string $aggregateType = null,
        public ?string $aggregateId = null,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $availableAt = null,
    ) {}
}
