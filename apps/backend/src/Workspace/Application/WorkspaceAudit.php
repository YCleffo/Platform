<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;

final readonly class WorkspaceAudit
{
    public function __construct(private AuditWriter $writer) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $action,
        ?string $actorAccountId,
        string $resourceType,
        string $resourceId,
        array $metadata = [],
        bool $success = true,
        ?string $errorCode = null,
    ): void {
        $cleanMeta = [];
        foreach ($metadata as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (in_array($k, ['actionKey', 'mode', 'status', 'entityType', 'moduleCode'], true) && is_string($v)) {
                $normalized = strtolower(preg_replace('/[^a-z0-9._-]+/i', '_', $v) ?? '');
                $normalized = trim($normalized, '._-');
                if ($normalized !== '') {
                    $cleanMeta[$k] = substr($normalized, 0, 120);
                }
                continue;
            }
            $cleanMeta[$k] = $v;
        }

        $this->writer->write(new WriteAuditEventCommand(
            actorType: $actorAccountId !== null ? 'account' : 'system',
            actorAccountId: $actorAccountId,
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            success: $success,
            errorCode: $errorCode,
            metadata: $cleanMeta,
        ));
    }
}
