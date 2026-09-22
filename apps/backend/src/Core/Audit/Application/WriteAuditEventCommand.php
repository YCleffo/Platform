<?php

declare(strict_types=1);

namespace App\Core\Audit\Application;

final readonly class WriteAuditEventCommand
{
    /**
     * @param array<array-key, mixed>|null $beforeData
     * @param array<array-key, mixed>|null $afterData
     * @param array<array-key, mixed> $metadata
     */
    public function __construct(
        public string $actorType,
        public ?string $actorAccountId,
        public string $action,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
        public ?string $requestId = null,
        public ?string $correlationId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public bool $success = true,
        public ?string $errorCode = null,
        public ?array $beforeData = null,
        public ?array $afterData = null,
        public array $metadata = [],
    ) {}
}
