<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use App\Workspace\Domain\WorkspaceAccess;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class WorkspacePreferencesService
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
        private WorkspaceAudit $audit,
    ) {}

    /** @return array<string, mixed> */
    public function getAll(AuthenticatedIdentity $identity, string $organizationId): array
    {
        $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $rows = $this->db->createCommand(
            'SELECT key, organization_id::text AS "organizationId", value::text AS "valueJson", updated_at::text AS "updatedAt"
             FROM workspace.preferences
             WHERE account_id = CAST(:account AS uuid)
               AND (organization_id = CAST(:org AS uuid) OR organization_id IS NULL)
             ORDER BY (organization_id IS NULL) ASC, key ASC',
            [':account' => $identity->accountId, ':org' => $orgId],
        )->queryAll();

        $preferences = [];
        $items = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $k = (string) $r['key'];
            $decoded = json_decode((string) ($r['valueJson'] ?? 'null'), true);
            if (!array_key_exists($k, $preferences)) {
                $preferences[$k] = $decoded;
            }
            $items[] = [
                'key' => $k,
                'organizationId' => $r['organizationId'],
                'value' => $decoded,
                'updatedAt' => (string) $r['updatedAt'],
            ];
        }

        return ['preferences' => $preferences, 'items' => $items];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function set(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $rawKey = $input['key'] ?? null;
        $key = is_string($rawKey) ? trim($rawKey) : '';
        if ($key === '' || strlen($key) > 120 || preg_match('/\A[a-zA-Z0-9._-]+\z/', $key) !== 1) {
            throw new HttpError(422, 'invalid_preference_key');
        }
        $scopeOrg = ($input['scope'] ?? 'organization') === 'global' ? null : $orgId;
        $value = $input['value'] ?? null;

        $this->upsert($identity->accountId, $scopeOrg, $key, $value);

        $this->audit->record(
            action: 'workspace.preference.updated',
            actorAccountId: $identity->accountId,
            resourceType: 'workspace.preference',
            resourceId: $identity->accountId,
            metadata: ['organizationId' => $orgId],
        );

        return $this->getAll($identity, $orgId);
    }

    /** @return array{existed: bool, value: mixed} */
    public function snapshot(string $accountId, ?string $organizationId, string $key): array
    {
        $sql = 'SELECT value::text AS "valueJson" FROM workspace.preferences WHERE account_id = CAST(:account AS uuid) AND key = :key';
        $params = [':account' => $accountId, ':key' => $key];
        if ($organizationId === null) {
            $sql .= ' AND organization_id IS NULL';
        } else {
            $sql .= ' AND organization_id = CAST(:org AS uuid)';
            $params[':org'] = $organizationId;
        }
        $row = $this->db->createCommand($sql, $params)->queryOne();
        if (!is_array($row)) {
            return ['existed' => false, 'value' => null];
        }
        return ['existed' => true, 'value' => json_decode((string) $row['valueJson'], true)];
    }

    public function upsert(string $accountId, ?string $organizationId, string $key, mixed $value): void
    {
        $this->db->createCommand(
            'INSERT INTO workspace.preferences (account_id, organization_id, key, value, updated_at)
             VALUES (CAST(:account AS uuid), CAST(:org AS uuid), :key, CAST(:val AS jsonb), now())
             ON CONFLICT (account_id, organization_id, key) DO UPDATE SET
                value = EXCLUDED.value,
                updated_at = now()',
            [
                ':account' => $accountId,
                ':org' => $organizationId,
                ':key' => $key,
                ':val' => json_encode($value, JSON_THROW_ON_ERROR),
            ],
        )->execute();
    }

    public function deleteKey(string $accountId, ?string $organizationId, string $key): void
    {
        $sql = 'DELETE FROM workspace.preferences WHERE account_id = CAST(:account AS uuid) AND key = :key';
        $params = [':account' => $accountId, ':key' => $key];
        if ($organizationId === null) {
            $sql .= ' AND organization_id IS NULL';
        } else {
            $sql .= ' AND organization_id = CAST(:org AS uuid)';
            $params[':org'] = $organizationId;
        }
        $this->db->createCommand($sql, $params)->execute();
    }
}
