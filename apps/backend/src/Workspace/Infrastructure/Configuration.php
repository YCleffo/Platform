<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure;

use App\Core\Audit\Application\AuditEventDefinition;
use App\Core\Privacy\Domain\DataCategoryDefinition;
use Yiisoft\Definitions\Reference;

/** Called only by the application composition root, never by Core domain code. */
final class Configuration
{
    /** @return array<string, mixed> */
    public static function params(): array
    {
        $audit = [];
        foreach ([
            'action.executed',
            'operation.undone',
            'operation.committed',
            'operation.cancelled',
            'operation.retried',
            'operation.failed',
            'trash.moved',
            'trash.restored',
            'trash.purged',
            'trash.emptied',
            'preference.updated',
        ] as $operation) {
            $audit[] = new AuditEventDefinition(
                'workspace.' . $operation,
                metadataFields: [
                    'organizationId' => 'uuid',
                    'actionKey' => 'code',
                    'mode' => 'code',
                    'status' => 'code',
                    'entityType' => 'code',
                    'entityId' => 'uuid',
                    'moduleCode' => 'code',
                    'revision' => 'int',
                    'count' => 'int',
                    'renamed' => 'bool',
                ],
            );
        }

        return [
            'auditEventDefinitions' => $audit,
            'additionalDataCategoryDefinitions' => [new DataCategoryDefinition('workspace')],
            'personalDataContributors' => [Reference::to(WorkspacePrivacyContributor::class)],
            'personalDataErasureHandlers' => [Reference::to(WorkspaceErasureHandler::class)],
        ];
    }
}
