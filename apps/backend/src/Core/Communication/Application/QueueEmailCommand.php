<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

final readonly class QueueEmailCommand
{
    /** @param array<string, scalar|null> $variables */
    public function __construct(
        public string $producerModule,
        public string $recipientAddress,
        public ?string $recipientName,
        public string $locale,
        public string $templateCode,
        public array $variables,
        public ?string $aggregateType = null,
        public ?string $aggregateId = null,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $availableAt = null,
    ) {}
}
