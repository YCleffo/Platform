<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Core\Audit\Application\AuditEventDefinition;
use App\Core\Privacy\Domain\DataCategoryDefinition;
use Yiisoft\Definitions\Reference;

/** Called only by the application composition root, never by Core domain code. */
final class Configuration
{
    /** @return array<string,mixed> */
    public static function params(): array
    {
        $audit = [];
        foreach (['create_group', 'enroll_student', 'create_lesson', 'create_event', 'mark_attendance', 'save_profile',
            'start_thread', 'send_message', 'read_thread', 'read_notification', 'upload_file', 'assign_teacher'] as $operation) {
            $audit[] = new AuditEventDefinition('education.' . $operation, metadataFields: ['organizationId' => 'uuid']);
        }
        return ['auditEventDefinitions' => $audit,
            'additionalDataCategoryDefinitions' => [new DataCategoryDefinition('education')],
            'personalDataContributors' => [Reference::to(PersonalDataContributor::class)],
            'personalDataCorrectionContributors' => [Reference::to(ProfileCorrection::class)],
            'retentionCandidateContributors' => [Reference::to(RetentionCandidateContributor::class)],
            'lawfulProcessingBasisContributors' => [Reference::to(LawfulProcessingBasisContributor::class)],
            'processingRestrictionContributors' => [Reference::to(ProcessingRestrictionContributor::class)],
            'personalDataErasureHandlers' => [Reference::to(WorkspaceErasureHandler::class)]];
    }
}
