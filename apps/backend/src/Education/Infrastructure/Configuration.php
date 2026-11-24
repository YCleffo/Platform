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
        foreach (['create_group', 'update_group', 'archive_group', 'enroll_student', 'remove_group_student',
            'transfer_student', 'create_lesson', 'update_lesson', 'complete_lesson', 'cancel_lesson', 'create_event',
            'mark_attendance', 'save_lesson_report', 'save_lesson_result', 'invite_student', 'revoke_student_account',
            'activate_invite', 'activities_assign', 'student_start_attempt', 'student_finish_attempt', 'student_save_attempt_state', 'revoke_activity_attempt', 'save_profile',
            'start_thread', 'send_message', 'read_thread', 'read_notification', 'react_message',
            'student_start_thread', 'student_send_message', 'student_read_thread', 'student_react_message',
            'upload_file', 'delete_file', 'assign_teacher', 'create_student', 'create_program', 'update_program',
            'create_curriculum_module', 'update_curriculum_module', 'create_curriculum_lesson', 'update_curriculum_lesson',
            'assign_program', 'set_group_teachers',
            'homework_create', 'homework_update', 'homework_publish', 'homework_close', 'homework_archive',
            'homework_add_recipients', 'homework_save_draft', 'homework_submit', 'homework_review',
            'homework_upload_material', 'homework_upload_submission', 'homework_delete_submission_file',
            'homework_start_activity', 'homework_attach_activity',
            'family_add', 'family_update', 'family_confirm', 'family_revoke', 'family_invite', 'family_revoke_invite',
            'parent_activate'] as $operation) {
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
