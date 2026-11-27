<?php

declare(strict_types=1);

namespace App\Activity\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;

/**
 * Domain authorization & recipient resolution port for the Universal Activity Engine.
 * Keeps App\Activity decoupled from product-specific schemas (e.g., Education groups/students/guardians).
 */
interface ActivityDomainPolicyInterface
{
    /**
     * @param array<string, mixed> $activityRow
     */
    public function canAccessActivity(
        AuthenticatedIdentity $identity,
        string $organizationId,
        array $activityRow,
    ): bool;

    /**
     * Whether the identity may start an open-practice attempt (no assignmentId) on a PUBLISHED activity.
     *
     * @param array<string, mixed> $activityRow
     */
    public function canStartOpenPractice(
        AuthenticatedIdentity $identity,
        string $organizationId,
        array $activityRow,
    ): bool;

    public function canAssignTarget(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $targetType,
        string $targetId,
    ): bool;

    /**
     * @return list<string>
     */
    public function resolveAssignmentRecipients(
        string $organizationId,
        string $targetType,
        string $targetId,
        string $assignerPersonId,
    ): array;

    /**
     * @param array<string, mixed> $assignmentRow
     */
    public function isAssignmentRecipient(
        AuthenticatedIdentity $identity,
        string $organizationId,
        array $assignmentRow,
    ): bool;

    /**
     * Whether the identity may see an assignment row in listAssignments().
     *
     * @param array<string, mixed> $assignmentRow
     */
    public function canViewAssignment(
        AuthenticatedIdentity $identity,
        string $organizationId,
        array $assignmentRow,
    ): bool;

    public function canAccessParticipantAttempt(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $participantPersonId,
        ?string $assignmentId,
        ?string $activityAuthorPersonId,
    ): bool;

    public function canViewParticipantAnalytics(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $targetPersonId,
    ): bool;
}
