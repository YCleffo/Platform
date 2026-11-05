<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Keeps the UUID as an FK anchor and irreversibly removes operational Person identity. */
final readonly class CorePersonAnonymizationHandler implements PersonalDataErasureHandler
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'core.person.anonymize';
    }
    public function resourceType(): string
    {
        return 'core.person';
    }
    public function supportsAction(string $action): bool
    {
        return $action === 'anonymize';
    }

    public function owns(string $subjectPersonId, string $resourceId): bool
    {
        $found = $this->db->createCommand(
            'SELECT 1 FROM core.persons WHERE id = CAST(:id AS uuid) FOR UPDATE',
            [':id' => $resourceId],
        )->queryScalar();
        return $subjectPersonId === $resourceId && $found !== false && $found !== null;
    }

    public function reconcile(string $itemId): ?string
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT p.id::text, p.status::text, p.archived_at, p.given_name, p.family_name,
                p.middle_name, p.display_name, p.birth_date
            FROM core.privacy_erasure_items i JOIN core.persons p ON p.id = i.resource_id
            JOIN core.privacy_erasure_cases c ON c.id = i.case_id
            WHERE i.id = CAST(:item AS uuid) AND i.resource_type = 'core.person'
                AND c.subject_person_id = p.id
            SQL, [':item' => $itemId])->queryOne();
        return is_array($row) && self::tombstoned($row) && $this->dependenciesClear(isset($row['id']) ? (string) $row['id'] : null)
            ? 'anonymized' : null;
    }

    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string
    {
        if ($action !== 'anonymize' || !$this->owns($subjectPersonId, $resourceId)) {
            throw new DomainException('Person anonymization ownership or action is invalid.');
        }
        $current = $this->db->createCommand(<<<'SQL'
            SELECT status::text, archived_at, given_name, family_name, middle_name,
                display_name, birth_date FROM core.persons WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $resourceId])->queryOne();
        if (!$this->dependenciesClear($resourceId)) {
            throw new DomainException('Person identity dependencies remain.');
        }
        if (is_array($current) && self::tombstoned($current)) {
            return 'anonymized';
        }
        $this->db->createCommand(<<<'SQL'
            UPDATE core.persons SET status = 'archived', archived_at = COALESCE(archived_at, now()),
                given_name = 'Anonymized', family_name = 'Person', middle_name = NULL,
                display_name = NULL, birth_date = NULL, updated_at = now()
            WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $resourceId])->execute();
        return 'anonymized';
    }

    private function dependenciesClear(?string $resourceId): bool
    {
        if ($resourceId === null) {
            return false;
        }
        $dependencies = $this->db->createCommand(<<<'SQL'
            SELECT EXISTS(SELECT 1 FROM core.person_contacts WHERE person_id = CAST(:id AS uuid))
                OR EXISTS(SELECT 1 FROM core.organization_memberships WHERE person_id = CAST(:id AS uuid))
                OR EXISTS(SELECT 1 FROM core.accounts a WHERE a.person_id = CAST(:id AS uuid)
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
            SQL, [':id' => $resourceId])->queryScalar();
        return in_array($dependencies, [false, 'f', '0', 0], true);
    }

    private static function tombstoned(array $row): bool
    {
        return $row['status'] === 'archived' && $row['archived_at'] !== null
            && $row['given_name'] === 'Anonymized' && $row['family_name'] === 'Person'
            && $row['middle_name'] === null && $row['display_name'] === null
            && $row['birth_date'] === null;
    }
}
