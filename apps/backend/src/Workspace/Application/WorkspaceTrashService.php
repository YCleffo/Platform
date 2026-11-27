<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use App\Storage\Application\StorageFileAdapter;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Objects;
use App\Storage\Infrastructure\S3StorageFileAdapter;
use App\Workspace\Domain\WorkspaceAccess;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class WorkspaceTrashService
{
    public function __construct(
        private ConnectionInterface $db,
        private WorkspaceAccess $access,
        private TrashProviderRegistry $providers,
        private WorkspaceAudit $audit,
        private RealtimeService $realtime,
        private ?StorageFileAdapter $storage = null,
        private ?S3Objects $s3Objects = null,
        private ?S3Config $s3Config = null,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listTrash(AuthenticatedIdentity $identity, string $organizationId, array $query = []): array
    {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $limit = max(1, min(100, isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 50));
        $offset = max(0, isset($query['offset']) && is_numeric($query['offset']) ? (int) $query['offset'] : 0);
        $entityType = is_string($query['entityType'] ?? null) && $query['entityType'] !== '' ? $query['entityType'] : null;
        if ($entityType === null && is_string($query['category'] ?? null) && $query['category'] !== '' && $query['category'] !== 'all') {
            $entityType = match ($query['category']) {
                'files' => 'storage.file',
                'documents' => 'media.asset',
                'tests', 'activities' => 'activity.item',
                'homework' => 'education.homework',
                default => null,
            };
        }

        $where = "organization_id = CAST(:org AS uuid) AND restore_status = 'trashed'";
        $params = [':org' => $orgId, ':limit' => $limit + 1, ':offset' => $offset];

        if (!$this->access->isOrgAdmin($identity)) {
            $where .= ' AND (deleted_by_account_id = CAST(:account AS uuid) OR deleted_by_person_id = CAST(:person AS uuid))';
            $params[':account'] = $identity->accountId;
            $params[':person'] = $personId;
        }
        if ($entityType !== null) {
            $where .= ' AND entity_type = :entityType';
            $params[':entityType'] = $entityType;
        }

        $rows = $this->db->createCommand(
            "SELECT id::text, organization_id::text AS \"organizationId\",
                    deleted_by_account_id::text AS \"deletedByAccountId\",
                    deleted_by_person_id::text AS \"deletedByPersonId\",
                    entity_type AS \"entityType\", entity_id::text AS \"entityId\",
                    module_code AS \"moduleCode\", title, subtitle,
                    metadata::text AS \"metadataJson\", restore_status AS \"restoreStatus\",
                    expires_at::text AS \"expiresAt\", deleted_at::text AS \"deletedAt\"
             FROM workspace.trash_items
             WHERE {$where}
             ORDER BY deleted_at DESC, id DESC
             LIMIT :limit OFFSET :offset",
            $params,
        )->queryAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $meta = json_decode((string) ($r['metadataJson'] ?? '{}'), true);
            $r['metadata'] = is_array($meta) ? $meta : [];
            unset($r['metadataJson']);
            $items[] = $r;
        }

        $countSql = "SELECT count(*) FROM workspace.trash_items WHERE organization_id = CAST(:org AS uuid) AND restore_status = 'trashed'";
        $countParams = [':org' => $orgId];
        if (!$this->access->isOrgAdmin($identity)) {
            $countSql .= ' AND (deleted_by_account_id = CAST(:account AS uuid) OR deleted_by_person_id = CAST(:person AS uuid))';
            $countParams[':account'] = $identity->accountId;
            $countParams[':person'] = $personId;
        }
        $total = (int) ($this->db->createCommand($countSql, $countParams)->queryScalar() ?: 0);

        return [
            'items' => $items,
            'total' => $total,
            'hasMore' => $hasMore,
            'offset' => $offset,
        ];
    }

    /** @return array<string, mixed> */
    public function moveToTrash(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $entityType,
        string $entityId,
    ): array {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $eid = RealtimeService::assertUuid($entityId, 'entityId');

        $row = $this->db->transaction(fn(): array => $this->moveToTrashInTransaction($identity, $orgId, $personId, $entityType, $eid));

        $this->audit->record(
            action: 'workspace.trash.moved',
            actorAccountId: $identity->accountId,
            resourceType: $entityType,
            resourceId: $eid,
            metadata: [
                'organizationId' => $orgId,
                'entityType' => $entityType,
                'entityId' => $eid,
                'moduleCode' => (string) $row['moduleCode'],
            ],
        );

        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'workspace.trash.updated',
            payload: ['action' => 'moved', 'trashItem' => $row],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'workspace_trash',
            aggregateId: (string) $row['id'],
        );

        return $row;
    }

    /**
     * Transactional core for moving an entity to Trash (shared with reversible `trash.move` action).
     *
     * @return array<string, mixed>
     */
    public function moveToTrashInTransaction(
        AuthenticatedIdentity $identity,
        string $orgId,
        string $personId,
        string $entityType,
        string $entityId,
    ): array {
        $provider = $this->providers->get($entityType);
        $snapshot = $provider->trash($identity, $orgId, $entityId);

        $row = $this->db->createCommand(
            "INSERT INTO workspace.trash_items (
                organization_id, deleted_by_account_id, deleted_by_person_id,
                entity_type, entity_id, module_code, title, subtitle, metadata, payload, restore_status
            ) VALUES (
                CAST(:org AS uuid), CAST(:account AS uuid), CAST(:person AS uuid),
                :entityType, CAST(:entityId AS uuid), :moduleCode, :title, :subtitle,
                CAST(:metadata AS jsonb), CAST(:payload AS jsonb), 'trashed'
            )
            RETURNING id::text, organization_id::text AS \"organizationId\",
                      deleted_by_account_id::text AS \"deletedByAccountId\",
                      deleted_by_person_id::text AS \"deletedByPersonId\",
                      entity_type AS \"entityType\", entity_id::text AS \"entityId\",
                      module_code AS \"moduleCode\", title, subtitle,
                      metadata::text AS \"metadataJson\", payload::text AS \"payloadJson\",
                      restore_status AS \"restoreStatus\", expires_at::text AS \"expiresAt\",
                      deleted_at::text AS \"deletedAt\"",
            [
                ':org' => $orgId,
                ':account' => $identity->accountId,
                ':person' => $personId,
                ':entityType' => $entityType,
                ':entityId' => $entityId,
                ':moduleCode' => $provider->moduleCode(),
                ':title' => mb_substr($snapshot['title'], 0, 255),
                ':subtitle' => mb_substr($snapshot['subtitle'], 0, 255),
                ':metadata' => $snapshot['metadata'] === [] ? '{}' : json_encode($snapshot['metadata'], JSON_THROW_ON_ERROR),
                ':payload' => $snapshot['payload'] === [] ? '{}' : json_encode($snapshot['payload'], JSON_THROW_ON_ERROR),
            ],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(500, 'trash_insert_failed');
        }

        $row['metadata'] = $snapshot['metadata'];
        $row['payload'] = $snapshot['payload'];
        unset($row['metadataJson'], $row['payloadJson']);

        return $row;
    }

    /** @return array<string, mixed> */
    public function restore(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $trashId,
        bool $renameOnConflict = false,
    ): array {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $tid = RealtimeService::assertUuid($trashId, 'trashId');

        $result = $this->db->transaction(fn(): array => $this->restoreInTransaction(
            $identity,
            $orgId,
            $personId,
            $tid,
            $renameOnConflict,
        ));

        $this->audit->record(
            action: 'workspace.trash.restored',
            actorAccountId: $identity->accountId,
            resourceType: (string) $result['entityType'],
            resourceId: (string) $result['entityId'],
            metadata: [
                'organizationId' => $orgId,
                'entityType' => (string) $result['entityType'],
                'entityId' => (string) $result['entityId'],
                'renamed' => (bool) $result['renamed'],
            ],
        );

        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'workspace.trash.updated',
            payload: ['action' => 'restored', 'trashId' => $tid, ...$result],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'workspace_trash',
            aggregateId: $tid,
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function restoreInTransaction(
        AuthenticatedIdentity $identity,
        string $orgId,
        string $personId,
        string $trashId,
        bool $renameOnConflict = false,
    ): array {
        $item = $this->loadAuthorizedTrashItemForUpdate($identity, $orgId, $personId, $trashId);
        if ($item['restoreStatus'] !== 'trashed') {
            throw new HttpError(409, 'trash_item_not_restorable');
        }

        $provider = $this->providers->get((string) $item['entityType']);
        $restored = $provider->restore($orgId, $item, $renameOnConflict);

        $this->db->createCommand(
            "UPDATE workspace.trash_items
             SET restore_status = 'restored', restored_at = now()
             WHERE id = CAST(:id AS uuid)",
            [':id' => $trashId],
        )->execute();

        return [
            'id' => $trashId,
            'entityType' => (string) $item['entityType'],
            'entityId' => $restored['entityId'],
            'restoredTitle' => $restored['restoredTitle'],
            'renamed' => $restored['renamed'],
            'restoreStatus' => 'restored',
        ];
    }

    /** @return array<string, mixed> */
    public function deletePermanent(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $trashId,
    ): array {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $tid = RealtimeService::assertUuid($trashId, 'trashId');

        /** @var array{entityType: string, entityId: string, keys: list<string>} $purged */
        $purged = $this->db->transaction(function () use ($identity, $orgId, $personId, $tid): array {
            $item = $this->loadAuthorizedTrashItemForUpdate($identity, $orgId, $personId, $tid);
            if ($item['restoreStatus'] !== 'trashed') {
                throw new HttpError(409, 'trash_item_already_finalized');
            }
            return $this->purgeItemInTransaction($orgId, $item);
        });

        $purgedS3Keys = $this->deleteUnreferencedStorageKeys($purged['keys']);

        $this->audit->record(
            action: 'workspace.trash.purged',
            actorAccountId: $identity->accountId,
            resourceType: $purged['entityType'],
            resourceId: $purged['entityId'],
            metadata: [
                'organizationId' => $orgId,
                'entityType' => $purged['entityType'],
                'entityId' => $purged['entityId'],
            ],
        );

        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'workspace.trash.updated',
            payload: ['action' => 'purged', 'trashId' => $tid, 'entityType' => $purged['entityType'], 'entityId' => $purged['entityId']],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'workspace_trash',
            aggregateId: $tid,
        );

        return [
            'id' => $tid,
            'entityType' => $purged['entityType'],
            'entityId' => $purged['entityId'],
            'purged' => true,
            'purgedStorageKeys' => $purgedS3Keys,
        ];
    }

    /** @return array{purgedCount: int, purgedStorageKeys: list<string>} */
    public function emptyTrash(AuthenticatedIdentity $identity, string $organizationId): array
    {
        $personId = $this->access->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $isAdmin = $this->access->isOrgAdmin($identity);

        /** @var array{count: int, keys: list<string>} $result */
        $result = $this->db->transaction(function () use ($identity, $orgId, $personId, $isAdmin): array {
            $sql = "SELECT id::text, organization_id::text AS \"organizationId\",
                           deleted_by_account_id::text AS \"deletedByAccountId\",
                           deleted_by_person_id::text AS \"deletedByPersonId\",
                           entity_type AS \"entityType\", entity_id::text AS \"entityId\",
                           module_code AS \"moduleCode\", title, subtitle,
                           metadata::text AS \"metadataJson\", payload::text AS \"payloadJson\",
                           restore_status AS \"restoreStatus\"
                    FROM workspace.trash_items
                    WHERE organization_id = CAST(:org AS uuid)
                      AND restore_status = 'trashed'";
            $params = [':org' => $orgId];
            if (!$isAdmin) {
                $sql .= ' AND (deleted_by_account_id = CAST(:account AS uuid) OR deleted_by_person_id = CAST(:person AS uuid))';
                $params[':account'] = $identity->accountId;
                $params[':person'] = $personId;
            }
            $sql .= ' ORDER BY deleted_at ASC LIMIT 200 FOR UPDATE';

            $rows = $this->db->createCommand($sql, $params)->queryAll();
            $keys = [];
            $count = 0;
            /** @var array<string, mixed> $row */
            foreach ($rows as $row) {
                $hydrated = self::hydrateTrashRow($row);
                $purged = $this->purgeItemInTransaction($orgId, $hydrated);
                ++$count;
                foreach ($purged['keys'] as $k) {
                    $keys[] = $k;
                }
            }
            return ['count' => $count, 'keys' => array_values(array_unique($keys))];
        });

        $purgedS3Keys = $this->deleteUnreferencedStorageKeys($result['keys']);

        $this->audit->record(
            action: 'workspace.trash.emptied',
            actorAccountId: $identity->accountId,
            resourceType: 'workspace.trash',
            resourceId: $orgId,
            metadata: ['organizationId' => $orgId, 'count' => $result['count']],
        );

        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'workspace.trash.updated',
            payload: ['action' => 'emptied', 'purgedCount' => $result['count']],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'workspace_trash',
            aggregateId: $orgId,
        );

        return [
            'purgedCount' => $result['count'],
            'purgedStorageKeys' => $purgedS3Keys,
        ];
    }

    public function cleanupExpired(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        /** @var array{count: int, keys: list<string>} $result */
        $result = $this->db->transaction(function () use ($limit): array {
            $rows = $this->db->createCommand(
                "SELECT id::text, organization_id::text AS \"organizationId\",
                        deleted_by_account_id::text AS \"deletedByAccountId\",
                        deleted_by_person_id::text AS \"deletedByPersonId\",
                        entity_type AS \"entityType\", entity_id::text AS \"entityId\",
                        module_code AS \"moduleCode\", title, subtitle,
                        metadata::text AS \"metadataJson\", payload::text AS \"payloadJson\",
                        restore_status AS \"restoreStatus\"
                 FROM workspace.trash_items
                 WHERE restore_status = 'trashed' AND expires_at <= now()
                 ORDER BY expires_at ASC
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED",
                [':limit' => $limit],
            )->queryAll();

            $count = 0;
            $keys = [];
            /** @var array<string, mixed> $row */
            foreach ($rows as $row) {
                $hydrated = self::hydrateTrashRow($row);
                $purged = $this->purgeItemInTransaction((string) $hydrated['organizationId'], $hydrated);
                ++$count;
                foreach ($purged['keys'] as $k) {
                    $keys[] = $k;
                }
            }
            return ['count' => $count, 'keys' => array_values(array_unique($keys))];
        });

        $this->deleteUnreferencedStorageKeys($result['keys']);
        return $result['count'];
    }

    /**
     * @param array<string, mixed> $item
     * @return array{entityType: string, entityId: string, keys: list<string>}
     */
    private function purgeItemInTransaction(string $orgId, array $item): array
    {
        $entityType = (string) $item['entityType'];
        $entityId = (string) $item['entityId'];
        $provider = $this->providers->get($entityType);
        $keys = $provider->purge($orgId, $item);

        $this->db->createCommand(
            "UPDATE workspace.trash_items
             SET restore_status = 'purged', purged_at = now()
             WHERE id = CAST(:id AS uuid)",
            [':id' => $item['id']],
        )->execute();

        return ['entityType' => $entityType, 'entityId' => $entityId, 'keys' => $keys];
    }

    /** @return array<string, mixed> */
    private function loadAuthorizedTrashItemForUpdate(
        AuthenticatedIdentity $identity,
        string $orgId,
        string $personId,
        string $trashId,
    ): array {
        $row = $this->db->createCommand(
            'SELECT id::text, organization_id::text AS "organizationId",
                    deleted_by_account_id::text AS "deletedByAccountId",
                    deleted_by_person_id::text AS "deletedByPersonId",
                    entity_type AS "entityType", entity_id::text AS "entityId",
                    module_code AS "moduleCode", title, subtitle,
                    metadata::text AS "metadataJson", payload::text AS "payloadJson",
                    restore_status AS "restoreStatus"
             FROM workspace.trash_items
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)
             FOR UPDATE',
            [':id' => $trashId, ':org' => $orgId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'trash_item_not_found');
        }
        if (!$this->access->isOrgAdmin($identity)
            && strcasecmp((string) $row['deletedByAccountId'], $identity->accountId) !== 0
            && strcasecmp((string) ($row['deletedByPersonId'] ?? ''), $personId) !== 0) {
            throw new HttpError(403, 'access_denied');
        }

        return self::hydrateTrashRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateTrashRow(array $row): array
    {
        $meta = json_decode((string) ($row['metadataJson'] ?? '{}'), true);
        $payload = json_decode((string) ($row['payloadJson'] ?? '{}'), true);
        $row['metadata'] = is_array($meta) ? $meta : [];
        $row['payload'] = is_array($payload) ? $payload : [];
        unset($row['metadataJson'], $row['payloadJson']);
        return $row;
    }

    /**
     * Deletes storage objects from S3/SeaweedFS only when no live database reference remains.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    private function deleteUnreferencedStorageKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $purged = [];
        foreach (array_values(array_unique($keys)) as $key) {
            if ($key === '') {
                continue;
            }
            $isUuid = preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $key) === 1;
            if ($isUuid) {
                if ($this->isUuidKeyReferenced($key)) {
                    continue;
                }
                if ($this->storage !== null) {
                    try {
                        if ($this->storage->exists($key)) {
                            $this->storage->delete($key);
                            $purged[] = $key;
                        }
                    } catch (Throwable) {
                        // Queue fallback in storage.binary_cleanup so no orphan survives
                        if ($this->access->tableExists('storage.binary_cleanup')) {
                            $this->db->createCommand(
                                'INSERT INTO storage.binary_cleanup(storage_key) VALUES(CAST(:key AS uuid)) ON CONFLICT(storage_key) DO NOTHING',
                                [':key' => $key],
                            )->execute();
                        }
                    }
                }
            } else {
                $inSessions = $this->db->createCommand(
                    'SELECT 1 FROM media.upload_sessions WHERE staging_key = :key LIMIT 1',
                    [':key' => $key],
                )->queryScalar();
                if ($inSessions !== false) {
                    continue;
                }
                if ($this->s3Objects !== null && $this->s3Config !== null) {
                    try {
                        $this->s3Objects->delete($this->s3Config->bucketStorage, $key);
                        $purged[] = $key;
                    } catch (Throwable) {
                        // Best-effort staging cleanup
                    }
                } elseif ($this->storage !== null && !$this->storage instanceof S3StorageFileAdapter) {
                    if ($this->storage->exists($key)) {
                        $this->storage->delete($key);
                        $purged[] = $key;
                    }
                }
            }
        }

        return $purged;
    }

    private function isUuidKeyReferenced(string $key): bool
    {
        $sql = 'SELECT 1 FROM storage.versions WHERE storage_key = CAST(:key AS uuid)
                UNION ALL
                SELECT 1 FROM media.assets WHERE storage_key = CAST(:key AS uuid)
                UNION ALL
                SELECT 1 FROM media.variants WHERE storage_key = CAST(:key AS uuid)';
        if ($this->access->tableExists('education.files')) {
            $sql .= ' UNION ALL SELECT 1 FROM education.files WHERE storage_key = CAST(:key AS uuid)';
        }
        $found = $this->db->createCommand($sql . ' LIMIT 1', [':key' => $key])->queryScalar();
        return $found !== false && $found !== null;
    }
}
