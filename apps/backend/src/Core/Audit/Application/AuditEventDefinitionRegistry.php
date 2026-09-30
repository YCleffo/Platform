<?php

declare(strict_types=1);

namespace App\Core\Audit\Application;

use App\Core\Security\Application\SecurityAction;
use InvalidArgumentException;

final class AuditEventDefinitionRegistry
{
    /** @var array<string, AuditEventDefinition> */
    private array $definitions = [];

    /** @param list<AuditEventDefinition> $additionalDefinitions */
    public function __construct(array $additionalDefinitions = [])
    {
        foreach (SecurityAction::all() as $action) {
            $this->definitions[$action] = self::coreDefinition($action);
        }
        $this->definitions['organization.created'] = new AuditEventDefinition(
            'organization.created', ['organizationId' => 'uuid', 'organizationCode' => 'code'],
        );
        foreach (['organization_membership.added', 'organization_membership.left'] as $action) {
            $this->definitions[$action] = new AuditEventDefinition(
                $action, ['organizationId' => 'uuid', 'personId' => 'uuid'],
            );
        }
        foreach (['service_credential.created', 'service_credential.rotated', 'service_credential.revoked'] as $action) {
            $this->definitions[$action] = new AuditEventDefinition(
                $action, ['accountId' => 'uuid', 'scopes' => 'code_list'],
            );
        }
        foreach ($additionalDefinitions as $definition) {
            if (str_starts_with($definition->action, 'privacy.') || isset($this->definitions[$definition->action])) {
                throw new InvalidArgumentException('Duplicate or reserved audit action definition.');
            }
            $this->definitions[$definition->action] = $definition;
        }
    }

    public function get(string $action): ?AuditEventDefinition
    {
        if (str_starts_with($action, 'privacy.')) {
            return match ($action) {
                'privacy.request.submitted', 'privacy.request.status_changed',
                'privacy.request.deadline_extended', 'privacy.request.completed',
                'privacy.request.cancelled', 'privacy.processing_block.created',
                'privacy.processing_block.released', 'privacy.request.correction_confirmed',
                'privacy.request.execution_completed', 'privacy.request.execution_failed',
                'privacy.data.export.issued', 'privacy.data.correction.completed',
                'privacy.legal_hold.created', 'privacy.legal_hold.released',
                'privacy.retention.evaluated', 'privacy.retention.decision_changed',
                'privacy.processing_termination.started', 'privacy.processing_termination.completed',
                'privacy.processing_termination.partially_completed', 'privacy.processing_termination.failed',
                'privacy.erasure.created', 'privacy.erasure.started',
                'privacy.erasure.item_completed', 'privacy.erasure.item_blocked',
                'privacy.erasure.failed', 'privacy.erasure.completed',
                'privacy.identity.account_anonymized', 'privacy.identity.person_anonymized'
                    => new AuditEventDefinition($action, [
                    'requestType' => 'code', 'fromStatus' => 'code', 'toStatus' => 'code',
                    'deadlineCode' => 'code', 'reasonCode' => 'code',
                    'scopeType' => 'code', 'purposeCode' => 'code',
                    'executionCode' => 'code', 'contributorCode' => 'code',
                    'resourceType' => 'code', 'changedPaths' => 'path_list',
                    'policyCode' => 'code', 'decisionCode' => 'code',
                    'action' => 'code', 'result' => 'code',
                    'caseId' => 'uuid',
                    'stoppedPurposeCodes' => 'code_list', 'retainedPurposeCodes' => 'code_list',
                ], [], []),
                default => $this->definitions[$action] ?? null,
            };
        }
        return $this->definitions[$action] ?? null;
    }

    private static function coreDefinition(string $action): AuditEventDefinition
    {
        $metadata = match ($action) {
            SecurityAction::LOGIN_SUCCESS => ['identifierType' => 'auth_method'],
            SecurityAction::LOGIN_FAILED => ['identifierType' => 'code', 'identifierDigest' => 'digest'],
            SecurityAction::SESSION_REVOKED_ALL => ['currentSessionId' => 'uuid', 'revokedCount' => 'int'],
            SecurityAction::ACCOUNT_APPROVED, SecurityAction::ACCOUNT_DISABLED,
            SecurityAction::ACCOUNT_ENABLED, SecurityAction::ACCOUNT_ARCHIVED => ['revokedSessions' => 'int'],
            SecurityAction::ACCOUNT_SETTINGS_UPDATED => ['changedPaths' => 'path_list'],
            SecurityAction::ROLE_ASSIGNED, SecurityAction::ROLE_REVOKED => ['roleId' => 'uuid', 'roleCode' => 'code'],
            SecurityAction::EXTERNAL_LOGIN_SUCCEEDED, SecurityAction::EXTERNAL_LOGIN_FAILED,
            SecurityAction::EXTERNAL_IDENTITY_LINKED, SecurityAction::EXTERNAL_IDENTITY_UNLINKED,
            SecurityAction::MFA_RECOVERY_EXTERNAL_REAUTH_SUCCEEDED,
            SecurityAction::MFA_RECOVERY_EXTERNAL_REAUTH_FAILED => ['provider' => 'code'],
            SecurityAction::MFA_REQUIRED => ['firstFactor' => 'code'],
            SecurityAction::MFA_VERIFIED, SecurityAction::MFA_SUCCESS => ['method' => 'auth_method'],
            SecurityAction::MFA_RECOVERY_COMPLETED => ['severity' => 'code', 'proofMethod' => 'code'],
            SecurityAction::MFA_RECOVERY_EXTERNAL_REAUTH_REQUIRED => ['providers' => 'code_list'],
            SecurityAction::PASSKEY_REGISTRATION_STARTED => ['rpId' => 'domain'],
            SecurityAction::PASSKEY_REGISTRATION_COMPLETED => ['backupEligible' => 'bool', 'backupState' => 'bool'],
            SecurityAction::PASSKEY_REGISTRATION_FAILED, SecurityAction::PASSKEY_AUTHENTICATION_FAILED => ['reason' => 'code'],
            SecurityAction::PASSKEY_COUNTER_ANOMALY => ['storedCount' => 'int', 'newCount' => 'int'],
            default => [],
        };
        $state = match ($action) {
            SecurityAction::ACCOUNT_APPROVED, SecurityAction::ACCOUNT_DISABLED,
            SecurityAction::ACCOUNT_ENABLED, SecurityAction::ACCOUNT_ARCHIVED => ['status' => 'code'],
            SecurityAction::SESSION_IP_CHANGED => ['ipAddress' => 'ip'],
            SecurityAction::MODULE_ENABLED, SecurityAction::MODULE_DISABLED => [
                'code' => 'code', 'enabled' => 'bool', 'enabledAt' => 'timestamp', 'disabledAt' => 'timestamp',
            ],
            SecurityAction::SYSTEM_SETTING_UPDATED, SecurityAction::SYSTEM_SETTING_DELETED => ['key' => 'code', 'isPublic' => 'bool'],
            SecurityAction::MODULE_SETTING_UPDATED, SecurityAction::MODULE_SETTING_DELETED => ['moduleCode' => 'code', 'key' => 'code'],
            SecurityAction::FEATURE_FLAG_UPDATED, SecurityAction::FEATURE_FLAG_DELETED => [
                'code' => 'code', 'moduleCode' => 'code', 'enabled' => 'bool', 'rolloutPercent' => 'int',
            ],
            default => [],
        };
        return new AuditEventDefinition($action, $metadata, $state, $state);
    }
}
