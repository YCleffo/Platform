<?php

declare(strict_types=1);

namespace App\Core\Messaging\Application;

final readonly class InboxEvent
{
    public function __construct(
        public int $id,
        public string $eventId,
        public string $consumer,
        public string $status,
        public int $attempts,
        public string $receivedAt,
        public ?string $processedAt,
        public ?string $lockedBy,
        public ?string $lockedUntil,
        public ?string $lastError,
    ) {}
}
