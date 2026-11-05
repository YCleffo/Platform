<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Account\Application\AccountLifecycleRepository;
use App\Core\Auth\Application\AuthCleanupRepository;
use App\Core\Privacy\Application\PersonalDataErasureHandler;
use App\Core\Rbac\Application\RoleRepository;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Uses the existing lifecycle and Auth cleanup boundary; machine accounts are excluded. */
final readonly class CoreHumanAccountAnonymizationHandler implements PersonalDataErasureHandler
{
    public function __construct(
        private ConnectionInterface $db,
        private AccountLifecycleRepository $lifecycle,
        private AuthCleanupRepository $auth,
        private RoleRepository $roles,
    ) {}

    public function code(): string
    {
        return 'core.human_account.anonymize';
    }
    public function resourceType(): string
    {
        return 'core.human_account';
    }
    public function supportsAction(string $action): bool
    {
        return $action === 'anonymize';
    }

    public function owns(string $subjectPersonId, string $resourceId): bool
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT account_type::text FROM core.accounts
            WHERE id = CAST(:id AS uuid) AND person_id = CAST(:subject AS uuid) FOR UPDATE
            SQL, [':id' => $resourceId, ':subject' => $subjectPersonId])->queryScalar();
        return $row === 'human';
    }

    public function reconcile(string $itemId): ?string
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT a.id::text, a.account_type::text, a.status::text, a.disabled_at,
                a.archived_at, a.label, a.locked_until, a.last_login_at
            FROM core.privacy_erasure_items i JOIN core.accounts a ON a.id = i.resource_id
            JOIN core.privacy_erasure_cases c ON c.id = i.case_id
            WHERE i.id = CAST(:item AS uuid) AND i.resource_type = 'core.human_account'
                AND a.person_id = c.subject_person_id
            SQL, [':item' => $itemId])->queryOne();
        return is_array($row) && $this->tombstoned($row) ? 'anonymized' : null;
    }

    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string
    {
        if ($action !== 'anonymize' || !$this->owns($subjectPersonId, $resourceId)) {
            throw new DomainException('Human account anonymization ownership or action is invalid.');
        }
        $current = $this->db->createCommand(<<<'SQL'
            SELECT id::text, account_type::text, status::text, disabled_at,
                archived_at, label, locked_until, last_login_at
            FROM core.accounts WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $resourceId])->queryOne();
        $settings = $this->db->createCommand(
            'SELECT 1 FROM core.account_settings WHERE account_id = CAST(:id AS uuid)',
            [':id' => $resourceId],
        )->queryScalar();
        if ($settings !== false && $settings !== null) {
            throw new DomainException('Account settings erasure dependency remains.');
        }
        if (is_array($current) && $this->tombstoned($current)) {
            return 'anonymized';
        }
        $status = $this->db->createCommand(
            'SELECT status::text FROM core.accounts WHERE id = CAST(:id AS uuid) FOR UPDATE',
            [':id' => $resourceId],
        )->queryScalar();
        if ($status !== 'archived') {
            // RBAC repository enforces the final usable superadmin guard before access is removed.
            foreach ($this->roles->findByAccountId($resourceId) as $role) {
                $this->roles->revoke($resourceId, $role->id);
            }
            $this->lifecycle->archive($resourceId);
        }
        $this->auth->eraseHumanIdentity($resourceId);
        $this->db->createCommand(<<<'SQL'
            UPDATE core.accounts SET status = 'archived', disabled_at = COALESCE(disabled_at, now()),
                archived_at = COALESCE(archived_at, now()), label = NULL,
                security_stamp = uuidv4(), locked_until = NULL, last_login_at = NULL,
                updated_at = now()
            WHERE id = CAST(:id AS uuid) AND account_type = 'human'
            SQL, [':id' => $resourceId])->execute();
        return 'anonymized';
    }

    private function tombstoned(array $row): bool
    {
        if ($row['account_type'] !== 'human' || $row['status'] !== 'archived'
            || $row['disabled_at'] === null || $row['archived_at'] === null
            || $row['label'] !== null || $row['locked_until'] !== null || $row['last_login_at'] !== null) {
            return false;
        }
        foreach (['auth_identifiers', 'auth_password_credentials', 'auth_sessions', 'auth_totp_factors',
            'auth_recovery_codes', 'auth_passkeys', 'auth_external_identities', 'auth_challenges'] as $table) {
            $found = $this->db->createCommand(
                "SELECT 1 FROM core.{$table} WHERE account_id = CAST(:id AS uuid) LIMIT 1",
                [':id' => $row['id']],
            )->queryScalar();
            if ($found !== false && $found !== null) {
                return false;
            }
        }
        return true;
    }
}
