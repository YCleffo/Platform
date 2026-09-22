<?php

declare(strict_types=1);

namespace App\Core\Audit\Application;

final readonly class AuditEventReceipt
{
    public function __construct(
        public int $id,
        public string $eventId,
        public string $occurredAt,
    ) {}
}
