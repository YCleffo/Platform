<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Audit\Application\AuditEventDefinition;
use Yiisoft\Definitions\Reference;

final class Configuration
{
    /** @return array<string,mixed> */
    public static function params(): array
    {
        $audit = [];
        foreach (['upload', 'restore', 'delete', 'rename', 'resolve_conflict', 'transfer_start', 'transfer_complete', 'transfer_abort'] as $operation) {
            $audit[] = new AuditEventDefinition('storage.' . $operation, metadataFields: ['organizationId' => 'uuid']);
        }
        return [
            'auditEventDefinitions' => $audit,
            'personalDataContributors' => [Reference::to(PersonalDataContributor::class)],
            'retentionCandidateContributors' => [Reference::to(RetentionCandidateContributor::class)],
            'retentionDependencyContributors' => [Reference::to(RecoveryDependencyContributor::class)],
            'lawfulProcessingBasisContributors' => [Reference::to(LawfulProcessingBasisContributor::class)],
            'processingRestrictionContributors' => [Reference::to(ProcessingRestrictionContributor::class)],
            'personalDataErasureHandlers' => [Reference::to(SpaceErasureHandler::class)],
        ];
    }
}
