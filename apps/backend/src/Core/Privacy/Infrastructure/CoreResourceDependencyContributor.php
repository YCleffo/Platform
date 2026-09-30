<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\RetentionDependencyContributor;
use App\Core\Privacy\Domain\RetentionCandidate;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Identity anchors wait for their operational dependants to complete. */
final readonly class CoreResourceDependencyContributor implements RetentionDependencyContributor
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string { return 'core.resource_dependency'; }

    public function supports(string $resourceType): bool
    {
        return in_array($resourceType,
            ['core.organization_membership', 'core.human_account', 'core.person'], true);
    }

    public function resolved(RetentionCandidate $candidate): bool
    {
        if (!$this->supports($candidate->resourceType)) { return false; }
        $sql = match ($candidate->resourceType) {
            'core.organization_membership' => <<<'SQL'
                SELECT m.status = 'left' FROM core.organization_memberships m
                WHERE m.id = CAST(:id AS uuid) AND m.person_id = CAST(:subject AS uuid)
                SQL,
            'core.human_account' => <<<'SQL'
                SELECT NOT EXISTS(SELECT 1 FROM core.account_settings
                    WHERE account_id = CAST(:id AS uuid))
                    AND EXISTS(SELECT 1 FROM core.accounts
                        WHERE id = CAST(:id AS uuid) AND person_id = CAST(:subject AS uuid)
                            AND account_type = 'human')
                SQL,
            'core.person' => <<<'SQL'
                SELECT NOT EXISTS(SELECT 1 FROM core.person_contacts
                    WHERE person_id = CAST(:id AS uuid))
                    AND NOT EXISTS(SELECT 1 FROM core.organization_memberships
                        WHERE person_id = CAST(:id AS uuid))
                    AND NOT EXISTS(SELECT 1 FROM core.accounts a
                        WHERE a.person_id = CAST(:id AS uuid)
                            AND (a.account_type <> 'human' OR a.status <> 'archived'
                                OR a.disabled_at IS NULL OR a.archived_at IS NULL
                                OR a.label IS NOT NULL OR a.locked_until IS NOT NULL OR a.last_login_at IS NOT NULL
                                OR EXISTS(SELECT 1 FROM core.auth_identifiers WHERE account_id = a.id)
                                OR EXISTS(SELECT 1 FROM core.auth_password_credentials WHERE account_id = a.id)
                                OR EXISTS(SELECT 1 FROM core.auth_sessions WHERE account_id = a.id)
                                OR EXISTS(SELECT 1 FROM core.auth_totp_factors WHERE account_id = a.id)
                                OR EXISTS(SELECT 1 FROM core.auth_recovery_codes WHERE account_id = a.id)
                                OR EXISTS(SELECT 1 FROM core.auth_passkeys WHERE account_id = a.id)
                                OR EXISTS(SELECT 1 FROM core.auth_external_identities WHERE account_id = a.id)
                                OR EXISTS(SELECT 1 FROM core.auth_challenges WHERE account_id = a.id)))
                SQL,
            default => throw new \DomainException('Unsupported identity dependency.'),
        };
        $params = [':id' => $candidate->resourceId];
        if ($candidate->resourceType !== 'core.person') {
            $params[':subject'] = $candidate->subjectPersonId;
        }
        $result = $this->db->createCommand($sql, $params)->queryScalar();
        return in_array($result, [true, 't', '1', 1], true);
    }
}
