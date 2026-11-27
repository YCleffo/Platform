<?php

declare(strict_types=1);

namespace App\Notifications\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Universal platform Notifications Center with per-category preferences, Web Push registry, and Realtime sync.
 */
final readonly class NotificationsService
{
    private const ZERO_ORG = '00000000-0000-0000-0000-000000000000';
    private const CATEGORIES = ['message', 'homework', 'assessment', 'activity', 'schedule', 'family', 'security', 'system', 'general'];

    public function __construct(
        private ConnectionInterface $db,
        private OrganizationRepository $organizations,
        private PermissionService $permissions,
        private RealtimeService $realtime,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listNotifications(AuthenticatedIdentity $identity, string $organizationId, array $query = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $limit = max(1, min(100, isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 50));
        $offset = max(0, isset($query['offset']) && is_numeric($query['offset']) ? (int) $query['offset'] : 0);
        $unreadOnly = ($query['unreadOnly'] ?? '') === '1' || ($query['unreadOnly'] ?? false) === true || ($query['unreadOnly'] ?? '') === 'true';
        $grouped = ($query['grouped'] ?? '') === '1' || ($query['grouped'] ?? false) === true || ($query['grouped'] ?? '') === 'true';
        $includeArchived = ($query['includeArchived'] ?? '') === '1' || ($query['includeArchived'] ?? false) === true;
        $categoriesFilter = [];
        $rawCategoryQuery = $query['category'] ?? null;
        if (is_string($rawCategoryQuery) && $rawCategoryQuery !== '') {
            foreach (explode(',', $rawCategoryQuery) as $rawCat) {
                $trimmed = trim($rawCat);
                if (in_array($trimmed, self::CATEGORIES, true)) {
                    $categoriesFilter[] = $trimmed;
                }
            }
            $categoriesFilter = array_values(array_unique($categoriesFilter));
        }

        $where = 'organization_id = CAST(:org AS uuid) AND person_id = CAST(:person AS uuid)';
        if (!$includeArchived) {
            $where .= ' AND archived_at IS NULL';
        }
        $params = [':org' => $orgId, ':person' => $personId, ':limit' => $limit + 1, ':offset' => $offset];
        if ($unreadOnly) {
            $where .= ' AND read_at IS NULL';
        }
        if ($categoriesFilter !== []) {
            $where .= ' AND category = ANY(CAST(:categories AS varchar[]))';
            $params[':categories'] = '{' . implode(',', $categoriesFilter) . '}';
        }

        $rows = $this->db->createCommand(
            "SELECT
                id::text, category, kind, title, body,
                room_id::text AS \"roomId\",
                message_id::text AS \"messageId\",
                resource_type AS \"resourceType\",
                resource_id::text AS \"resourceId\",
                resource_path AS \"resourcePath\",
                group_key AS \"groupKey\",
                parent_student_id::text AS \"parentStudentId\",
                priority,
                payload::text AS \"payloadJson\",
                created_at::text AS \"createdAt\",
                read_at::text AS \"readAt\",
                archived_at::text AS \"archivedAt\"
             FROM notifications.items
             WHERE {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset",
            $params,
        )->queryAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        /** @var list<array<string, mixed>> $typedRows */
        $typedRows = $rows;
        $availability = $this->resolveResourceAvailability($orgId, $typedRows);

        $items = [];
        foreach ($typedRows as $r) {
            $payload = json_decode((string) ($r['payloadJson'] ?? '{}'), true);
            $r['payload'] = is_array($payload) ? $payload : [];
            unset($r['payloadJson']);
            $rawRtype = $r['resourceType'] ?? null;
            $rawRid = $r['resourceId'] ?? null;
            $rawMid = $r['messageId'] ?? null;
            $rtype = is_string($rawRtype) ? $rawRtype : null;
            $rid = is_string($rawRid) ? $rawRid : null;
            $mid = is_string($rawMid) && $rawMid !== '' ? $rawMid : null;
            $resAvail = ($rtype !== null && $rid !== null)
                ? ($availability[$rtype . ':' . strtolower($rid)] ?? true)
                : true;
            if ($resAvail && $mid !== null) {
                $resAvail = $availability['chat_message:' . strtolower($mid)] ?? true;
            }
            $r['resourceAvailable'] = $resAvail;
            $items[] = $r;
        }

        $response = [
            'items' => $items,
            'hasMore' => $hasMore,
            'offset' => $offset,
            'unreadCount' => $this->unreadCount($orgId, $personId),
            'pushStatus' => $this->buildPushStatus($identity->accountId, $orgId),
        ];

        if ($grouped) {
            /** @var array<string, array{groupKey: string, category: string, count: int, unreadCount: int, latestAt: string, items: list<array<string, mixed>>}> $groupsMap */
            $groupsMap = [];
            foreach ($items as $item) {
                $rawGroupKey = $item['groupKey'] ?? null;
                $gKey = is_string($rawGroupKey) && $rawGroupKey !== ''
                    ? $rawGroupKey
                    : (string) $item['category'];
                if (!isset($groupsMap[$gKey])) {
                    $groupsMap[$gKey] = [
                        'groupKey' => $gKey,
                        'category' => (string) $item['category'],
                        'count' => 0,
                        'unreadCount' => 0,
                        'latestAt' => (string) $item['createdAt'],
                        'items' => [],
                    ];
                }
                ++$groupsMap[$gKey]['count'];
                if (($item['readAt'] ?? null) === null) {
                    ++$groupsMap[$gKey]['unreadCount'];
                }
                $groupsMap[$gKey]['items'][] = $item;
            }
            $response['groups'] = array_values($groupsMap);
        }

        return $response;
    }

    public function unreadCount(string $organizationId, string $personId): int
    {
        $cnt = $this->db->createCommand(
            'SELECT count(*) FROM notifications.items
             WHERE organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid)
               AND read_at IS NULL
               AND archived_at IS NULL',
            [':org' => $organizationId, ':person' => $personId],
        )->queryScalar();
        return is_numeric($cnt) ? (int) $cnt : 0;
    }

    /**
     * Emits a persistent notification + real-time push event. Safe inside or outside caller transactions.
     *
     * @param array<string, mixed> $payload
     */
    public function notify(
        string $organizationId,
        string $personId,
        string $category,
        string $kind,
        string $title,
        string $body = '',
        ?string $roomId = null,
        ?string $messageId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $resourcePath = null,
        ?string $parentStudentId = null,
        ?string $eventKey = null,
        string $priority = 'normal',
        array $payload = [],
        ?string $accountId = null,
        ?string $groupKey = null,
    ): ?array {
        $cat = in_array($category, self::CATEGORIES, true) ? $category : 'general';
        $prio = in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal';
        $resolvedGroupKey = $groupKey !== null && $groupKey !== '' ? mb_substr($groupKey, 0, 160) : $cat;

        $row = $this->db->createCommand(
            'INSERT INTO notifications.items (
                organization_id, person_id, account_id, category, kind, title, body,
                room_id, message_id, resource_type, resource_id, resource_path,
                event_key, group_key, parent_student_id, priority, payload
            ) VALUES (
                CAST(:org AS uuid), CAST(:person AS uuid), CAST(:account AS uuid), :cat, :kind, :title, :body,
                CAST(:room AS uuid), CAST(:msg AS uuid), :rtype, CAST(:rid AS uuid), :rpath,
                :ek, :gkey, CAST(:parentStudent AS uuid), :prio, CAST(:payload AS jsonb)
            )
            ON CONFLICT (organization_id, person_id, event_key) WHERE event_key IS NOT NULL DO NOTHING
            RETURNING
                id::text, category, kind, title, body,
                room_id::text AS "roomId", message_id::text AS "messageId",
                resource_type AS "resourceType", resource_id::text AS "resourceId",
                resource_path AS "resourcePath", group_key AS "groupKey",
                priority, created_at::text AS "createdAt"',
            [
                ':org' => $organizationId,
                ':person' => $personId,
                ':account' => $accountId,
                ':cat' => $cat,
                ':kind' => $kind,
                ':title' => mb_substr($title, 0, 255),
                ':body' => $body,
                ':room' => $roomId,
                ':msg' => $messageId,
                ':rtype' => $resourceType,
                ':rid' => $resourceId,
                ':rpath' => $resourcePath,
                ':ek' => $eventKey,
                ':gkey' => $resolvedGroupKey,
                ':parentStudent' => $parentStudentId,
                ':prio' => $prio,
                ':payload' => $payload === [] ? '{}' : json_encode($payload, JSON_THROW_ON_ERROR),
            ],
        )->queryOne();

        if (!is_array($row)) {
            return null;
        }

        $row['payload'] = $payload;
        $this->realtime->publish(
            organizationId: $organizationId,
            channel: 'user:' . $personId,
            eventType: 'notification.created',
            payload: $row,
            actorAccountId: $accountId,
            actorPersonId: null,
            targetPersonIds: [$personId],
            aggregateType: 'notification',
            aggregateId: (string) $row['id'],
        );

        return $row;
    }

    /**
     * Batch-inserts notifications for multiple recipients and returns outbox event specs
     * so callers inside a transaction can publish them in one batch and push after commit.
     *
     * @param list<string> $personIds
     * @param array<string, mixed> $payload
     * @return list<\App\Realtime\Application\RealtimeEvent>
     */
    public function notifyBatch(
        string $organizationId,
        array $personIds,
        string $category,
        string $kind,
        string $title,
        string $body = '',
        ?string $roomId = null,
        ?string $messageId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $resourcePath = null,
        string $priority = 'normal',
        array $payload = [],
    ): array {
        $cleanPersonIds = array_values(array_unique(array_filter($personIds, static fn(string $p): bool => $p !== '')));
        if ($cleanPersonIds === []) {
            return [];
        }

        $cat = in_array($category, self::CATEGORIES, true) ? $category : 'general';
        $prio = in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal';
        $cleanTitle = mb_substr($title, 0, 255);
        $payloadJson = $payload === [] ? '{}' : json_encode($payload, JSON_THROW_ON_ERROR);

        $valuesSql = [];
        $params = [
            ':org' => $organizationId,
            ':cat' => $cat,
            ':kind' => $kind,
            ':title' => $cleanTitle,
            ':body' => $body,
            ':room' => $roomId,
            ':msg' => $messageId,
            ':rtype' => $resourceType,
            ':rid' => $resourceId,
            ':rpath' => $resourcePath,
            ':prio' => $prio,
            ':payload' => $payloadJson,
        ];
        foreach ($cleanPersonIds as $i => $pid) {
            $valuesSql[] = sprintf(
                '(CAST(:org AS uuid), CAST(:person%d AS uuid), :cat, :kind, :title, :body, CAST(:room AS uuid), CAST(:msg AS uuid), :rtype, CAST(:rid AS uuid), :rpath, :prio, CAST(:payload AS jsonb))',
                $i,
            );
            $params[':person' . $i] = $pid;
        }

        $rows = $this->db->createCommand(
            'INSERT INTO notifications.items (
                organization_id, person_id, category, kind, title, body,
                room_id, message_id, resource_type, resource_id, resource_path,
                priority, payload
             ) VALUES ' . implode(', ', $valuesSql) . '
             RETURNING
                id::text, person_id::text AS "personId", category, kind, title, body,
                room_id::text AS "roomId", message_id::text AS "messageId",
                resource_type AS "resourceType", resource_id::text AS "resourceId",
                resource_path AS "resourcePath", priority, created_at::text AS "createdAt"',
            $params,
        )->queryAll();

        $eventSpecs = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $pid = (string) ($r['personId'] ?? '');
            if ($pid === '') {
                continue;
            }
            unset($r['personId']);
            $r['payload'] = $payload;
            $eventSpecs[] = [
                'organizationId' => $organizationId,
                'channel' => 'user:' . $pid,
                'eventType' => 'notification.created',
                'payload' => $r,
                'actorAccountId' => null,
                'actorPersonId' => null,
                'targetPersonIds' => [$pid],
                'aggregateType' => 'notification',
                'aggregateId' => (string) $r['id'],
            ];
        }

        return $this->realtime->publishBatch($eventSpecs);
    }

    /** @return array{read: true, unreadCount: int} */
    public function markRead(AuthenticatedIdentity $identity, string $organizationId, string $id): array
    {
        $personId = $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $notifId = RealtimeService::assertUuid($id, 'id');

        $updated = $this->db->createCommand(
            'UPDATE notifications.items SET read_at = COALESCE(read_at, now())
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid) AND person_id = CAST(:person AS uuid)
             RETURNING id::text',
            [':id' => $notifId, ':org' => $orgId, ':person' => $personId],
        )->queryScalar();
        if (!is_string($updated)) {
            throw new HttpError(404, 'not_found');
        }

        // Keep legacy education.notifications synchronized when shared ID exists
        $this->db->createCommand(
            'UPDATE education.notifications SET read_at = COALESCE(read_at, now()) WHERE id = CAST(:id AS uuid)',
            [':id' => $notifId],
        )->execute();

        $unread = $this->unreadCount($orgId, $personId);
        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'notification.read',
            payload: ['id' => $notifId, 'unreadCount' => $unread],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'notification',
            aggregateId: $notifId,
        );

        return ['read' => true, 'unreadCount' => $unread];
    }

    /** @return array{readAll: true, unreadCount: int} */
    public function markAllRead(AuthenticatedIdentity $identity, string $organizationId, ?string $category = null): array
    {
        $personId = $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $sql = 'UPDATE notifications.items SET read_at = now()
                WHERE organization_id = CAST(:org AS uuid) AND person_id = CAST(:person AS uuid) AND read_at IS NULL';
        $params = [':org' => $orgId, ':person' => $personId];
        if ($category !== null && in_array($category, self::CATEGORIES, true)) {
            $sql .= ' AND category = :category';
            $params[':category'] = $category;
        }
        $this->db->createCommand($sql, $params)->execute();

        $unread = $this->unreadCount($orgId, $personId);
        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'notification.read_all',
            payload: ['category' => $category, 'unreadCount' => $unread],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'notification',
            aggregateId: $personId,
        );

        return ['readAll' => true, 'unreadCount' => $unread];
    }

    /**
     * Marks a batch of notification IDs (or category/all) as read and emits notifications.updated.
     *
     * @param array<string, mixed> $input
     * @return array{updated: int, ids: list<string>, unreadCount: int}
     */
    public function bulkRead(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $ids = $this->extractUuidList($input['ids'] ?? null);
        $category = is_string($input['category'] ?? null) && in_array($input['category'], self::CATEGORIES, true)
            ? $input['category'] : null;
        $all = ($input['all'] ?? false) === true;

        if ($ids === [] && $category === null && !$all) {
            return ['updated' => 0, 'ids' => [], 'unreadCount' => $this->unreadCount($orgId, $personId)];
        }

        $sql = 'UPDATE notifications.items SET read_at = COALESCE(read_at, now())
                WHERE organization_id = CAST(:org AS uuid)
                  AND person_id = CAST(:person AS uuid)
                  AND read_at IS NULL
                  AND archived_at IS NULL';
        $params = [':org' => $orgId, ':person' => $personId];
        if ($ids !== []) {
            $sql .= ' AND id = ANY(CAST(:ids AS uuid[]))';
            $params[':ids'] = '{' . implode(',', $ids) . '}';
        } elseif ($category !== null) {
            $sql .= ' AND category = :category';
            $params[':category'] = $category;
        }
        $sql .= ' RETURNING id::text';

        /** @var list<string> $updatedIds */
        $updatedIds = $this->db->createCommand($sql, $params)->queryColumn();

        if ($updatedIds !== []) {
            $hasEduNotif = $this->db->createCommand("SELECT to_regclass('education.notifications') IS NOT NULL")->queryScalar();
            if (in_array($hasEduNotif, [true, 1, '1', 't'], true)) {
                $this->db->createCommand(
                    'UPDATE education.notifications SET read_at = COALESCE(read_at, now()) WHERE id = ANY(CAST(:ids AS uuid[]))',
                    [':ids' => '{' . implode(',', $updatedIds) . '}'],
                )->execute();
            }
        }

        $unread = $this->unreadCount($orgId, $personId);
        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'notifications.updated',
            payload: ['action' => 'bulk_read', 'updated' => count($updatedIds), 'ids' => $updatedIds, 'unreadCount' => $unread],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'notification',
            aggregateId: $personId,
        );

        return ['updated' => count($updatedIds), 'ids' => $updatedIds, 'unreadCount' => $unread];
    }

    /**
     * Archives a batch of notifications (by IDs, category, or readOnly) and emits notifications.updated.
     *
     * @param array<string, mixed> $input
     * @return array{archived: int, ids: list<string>, unreadCount: int}
     */
    public function bulkArchive(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $ids = $this->extractUuidList($input['ids'] ?? null);
        $category = is_string($input['category'] ?? null) && in_array($input['category'], self::CATEGORIES, true)
            ? $input['category'] : null;
        $readOnly = ($input['readOnly'] ?? false) === true;
        $all = ($input['all'] ?? false) === true;

        if ($ids === [] && $category === null && !$readOnly && !$all) {
            return ['archived' => 0, 'ids' => [], 'unreadCount' => $this->unreadCount($orgId, $personId)];
        }

        $sql = 'UPDATE notifications.items SET archived_at = COALESCE(archived_at, now())
                WHERE organization_id = CAST(:org AS uuid)
                  AND person_id = CAST(:person AS uuid)
                  AND archived_at IS NULL';
        $params = [':org' => $orgId, ':person' => $personId];
        if ($ids !== []) {
            $sql .= ' AND id = ANY(CAST(:ids AS uuid[]))';
            $params[':ids'] = '{' . implode(',', $ids) . '}';
        } else {
            if ($category !== null) {
                $sql .= ' AND category = :category';
                $params[':category'] = $category;
            }
            if ($readOnly) {
                $sql .= ' AND read_at IS NOT NULL';
            }
        }
        $sql .= ' RETURNING id::text';

        /** @var list<string> $archivedIds */
        $archivedIds = $this->db->createCommand($sql, $params)->queryColumn();

        $unread = $this->unreadCount($orgId, $personId);
        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'notifications.updated',
            payload: ['action' => 'bulk_archive', 'archived' => count($archivedIds), 'ids' => $archivedIds, 'unreadCount' => $unread],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'notification',
            aggregateId: $personId,
        );

        return ['archived' => count($archivedIds), 'ids' => $archivedIds, 'unreadCount' => $unread];
    }

    /**
     * Reports Web Push (VAPID) configuration and subscription state honestly.
     *
     * @return array{configured: bool, active: bool, status: string, vapidPublicKey: ?string, subscriptionCount: int, reason: ?string}
     */
    public function pushStatus(AuthenticatedIdentity $identity, string $organizationId): array
    {
        $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        return $this->buildPushStatus($identity->accountId, $orgId);
    }

    /** @return array{configured: bool, active: bool, status: string, vapidPublicKey: ?string, subscriptionCount: int, reason: ?string} */
    private function buildPushStatus(string $accountId, string $orgId): array
    {
        $publicKey = self::readEnv('WEB_PUSH_VAPID_PUBLIC_KEY');
        $privateKey = self::readEnv('WEB_PUSH_VAPID_PRIVATE_KEY');
        $configured = $publicKey !== '' && $privateKey !== '';

        $countRaw = $this->db->createCommand(
            'SELECT count(*) FROM notifications.push_subscriptions
             WHERE account_id = CAST(:account AS uuid)
               AND (organization_id = CAST(:org AS uuid) OR organization_id IS NULL)
               AND revoked_at IS NULL',
            [':account' => $accountId, ':org' => $orgId],
        )->queryScalar();
        $subscriptionCount = is_numeric($countRaw) ? (int) $countRaw : 0;

        if (!$configured) {
            return [
                'configured' => false,
                'active' => false,
                'status' => 'not_configured',
                'vapidPublicKey' => null,
                'subscriptionCount' => $subscriptionCount,
                'reason' => 'vapid_keys_not_configured',
            ];
        }

        return [
            'configured' => true,
            'active' => $subscriptionCount > 0,
            'status' => $subscriptionCount > 0 ? 'active' : 'ready_unsubscribed',
            'vapidPublicKey' => $publicKey,
            'subscriptionCount' => $subscriptionCount,
            'reason' => null,
        ];
    }

    /**
     * Batch-checks whether notification targets are still active and not trashed/deleted.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, bool>
     */
    private function resolveResourceAvailability(string $orgId, array $rows): array
    {
        /** @var array<string, list<string>> $byType */
        $byType = [];
        $allIds = [];
        foreach ($rows as $row) {
            $type = $row['resourceType'] ?? null;
            $id = $row['resourceId'] ?? null;
            if (is_string($type) && $type !== '' && is_string($id) && $id !== '') {
                $lid = strtolower($id);
                $byType[$type][$lid] = $lid;
                $allIds[$lid] = $lid;
            }
            $msgId = $row['messageId'] ?? null;
            if (is_string($msgId) && $msgId !== '') {
                $lmid = strtolower($msgId);
                $byType['chat_message'][$lmid] = $lmid;
                $allIds[$lmid] = $lmid;
            }
        }
        if ($allIds === []) {
            return [];
        }

        $result = [];
        foreach ($byType as $type => $idMap) {
            foreach ($idMap as $lid) {
                $result[$type . ':' . $lid] = true;
            }
        }

        $hasWorkspace = $this->db->createCommand("SELECT to_regclass('workspace.trash_items') IS NOT NULL")->queryScalar();
        if (in_array($hasWorkspace, [true, 1, '1', 't'], true)) {
            $trashedRows = $this->db->createCommand(
                "SELECT entity_type, entity_id::text AS entity_id
                 FROM workspace.trash_items
                 WHERE organization_id = CAST(:org AS uuid)
                   AND restore_status = 'trashed'
                   AND entity_id = ANY(CAST(:ids AS uuid[]))",
                [':org' => $orgId, ':ids' => '{' . implode(',', array_values($allIds)) . '}'],
            )->queryAll();
            $trashedById = [];
            foreach ($trashedRows as $tr) {
                $tid = strtolower((string) ($tr['entity_id'] ?? ''));
                $ttype = (string) ($tr['entity_type'] ?? '');
                if ($tid !== '') {
                    $trashedById[$tid] = true;
                    $result[$ttype . ':' . $tid] = false;
                }
            }
            foreach ($byType as $type => $idMap) {
                foreach ($idMap as $lid) {
                    if (isset($trashedById[$lid])) {
                        $result[$type . ':' . $lid] = false;
                    }
                }
            }
        }

        $checks = [
            'storage.file' => 'SELECT id::text FROM storage.files WHERE id = ANY(CAST(:ids AS uuid[])) AND deleted_at IS NULL',
            'file' => 'SELECT id::text FROM storage.files WHERE id = ANY(CAST(:ids AS uuid[])) AND deleted_at IS NULL',
            'media.asset' => "SELECT id::text FROM media.assets WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND deleted_at IS NULL AND status <> 'CANCELLED'",
            'media' => "SELECT id::text FROM media.assets WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND deleted_at IS NULL AND status <> 'CANCELLED'",
            'activity' => "SELECT id::text FROM activity.items WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND deleted_at IS NULL AND status <> 'ARCHIVED'",
            'activity.definition' => "SELECT id::text FROM activity.items WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND deleted_at IS NULL AND status <> 'ARCHIVED'",
            'chat_room' => 'SELECT id::text FROM chat.rooms WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND archived_at IS NULL',
            'chat.room' => 'SELECT id::text FROM chat.rooms WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND archived_at IS NULL',
            'chat_message' => 'SELECT id::text FROM chat.messages WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND deleted_at IS NULL AND deleted_for_all = false',
            'homework' => 'SELECT id::text FROM education.homework_assignments WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND deleted_at IS NULL AND archived_at IS NULL',
            'lesson' => "SELECT id::text FROM education.lessons WHERE organization_id = CAST(:org AS uuid) AND id = ANY(CAST(:ids AS uuid[])) AND status <> 'cancelled'",
        ];

        foreach ($checks as $type => $sql) {
            if (!isset($byType[$type])) {
                continue;
            }
            if ($type === 'homework' || $type === 'lesson') {
                $tbl = $type === 'homework' ? 'education.homework_assignments' : 'education.lessons';
                $exists = $this->db->createCommand("SELECT to_regclass(:tbl) IS NOT NULL", [':tbl' => $tbl])->queryScalar();
                if (!in_array($exists, [true, 1, '1', 't'], true)) {
                    continue;
                }
            }
            $idsParam = '{' . implode(',', array_values($byType[$type])) . '}';
            $params = str_contains($sql, ':org')
                ? [':org' => $orgId, ':ids' => $idsParam]
                : [':ids' => $idsParam];
            $liveRows = $this->db->createCommand($sql, $params)->queryColumn();
            $liveSet = [];
            foreach ($liveRows as $liveId) {
                $liveSet[strtolower((string) $liveId)] = true;
            }
            foreach ($byType[$type] as $lid) {
                if (!isset($liveSet[$lid])) {
                    $result[$type . ':' . $lid] = false;
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function extractUuidList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice($raw, 0, 200) as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = RealtimeService::assertUuid($item, 'ids');
            }
        }
        return array_values(array_unique($out));
    }

    private static function readEnv(string $name): string
    {
        $val = getenv($name);
        if (is_string($val) && $val !== '') {
            return trim($val);
        }
        if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
            return trim($_ENV[$name]);
        }
        return '';
    }

    /** @return list<array<string, mixed>> */
    public function preferences(AuthenticatedIdentity $identity, string $organizationId): array
    {
        $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $rows = $this->db->createCommand(
            'SELECT category, in_app AS "inApp", realtime, web_push AS "webPush", email, sound
             FROM notifications.preferences
             WHERE account_id = CAST(:account AS uuid) AND organization_id IN (CAST(:org AS uuid), CAST(:zero AS uuid))',
            [':account' => $identity->accountId, ':org' => $orgId, ':zero' => self::ZERO_ORG],
        )->queryAll();

        $byCat = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $byCat[(string) $r['category']] = [
                'category' => (string) $r['category'],
                'inApp' => (bool) $r['inApp'],
                'realtime' => (bool) $r['realtime'],
                'webPush' => (bool) $r['webPush'],
                'email' => (bool) $r['email'],
                'sound' => (bool) $r['sound'],
            ];
        }

        $out = [];
        foreach (self::CATEGORIES as $cat) {
            $out[] = $byCat[$cat] ?? [
                'category' => $cat,
                'inApp' => true,
                'realtime' => true,
                'webPush' => true,
                'email' => in_array($cat, ['security', 'assessment', 'homework'], true),
                'sound' => true,
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $input */
    public function updatePreference(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $cat = is_string($input['category'] ?? null) && in_array($input['category'], self::CATEGORIES, true)
            ? $input['category']
            : throw new HttpError(422, 'invalid_category');

        $this->db->createCommand(
            'INSERT INTO notifications.preferences (account_id, organization_id, category, in_app, realtime, web_push, email, sound, updated_at)
             VALUES (CAST(:account AS uuid), CAST(:org AS uuid), :cat, :inApp, :realtime, :webPush, :email, :sound, now())
             ON CONFLICT (account_id, organization_id, category) DO UPDATE SET
                in_app = EXCLUDED.in_app,
                realtime = EXCLUDED.realtime,
                web_push = EXCLUDED.web_push,
                email = EXCLUDED.email,
                sound = EXCLUDED.sound,
                updated_at = now()',
            [
                ':account' => $identity->accountId,
                ':org' => $orgId,
                ':cat' => $cat,
                ':inApp' => (bool) ($input['inApp'] ?? true),
                ':realtime' => (bool) ($input['realtime'] ?? true),
                ':webPush' => (bool) ($input['webPush'] ?? true),
                ':email' => (bool) ($input['email'] ?? false),
                ':sound' => (bool) ($input['sound'] ?? true),
            ],
        )->execute();

        return ['items' => $this->preferences($identity, $orgId)];
    }

    /** @param array<string, mixed> $input */
    public function registerPushSubscription(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId);
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new HttpError(422, 'invalid_push_endpoint');
        }

        $id = (string) $this->db->createCommand(
            'INSERT INTO notifications.push_subscriptions (account_id, person_id, organization_id, endpoint, p256dh, auth_secret, device_label, revoked_at)
             VALUES (CAST(:account AS uuid), CAST(:person AS uuid), CAST(:org AS uuid), :endpoint, :p256dh, :auth, :label, NULL)
             ON CONFLICT (account_id, endpoint) DO UPDATE SET
                organization_id = EXCLUDED.organization_id,
                p256dh = EXCLUDED.p256dh,
                auth_secret = EXCLUDED.auth_secret,
                device_label = EXCLUDED.device_label,
                revoked_at = NULL
             RETURNING id::text',
            [
                ':account' => $identity->accountId,
                ':person' => $personId,
                ':org' => $orgId,
                ':endpoint' => $endpoint,
                ':p256dh' => (string) ($input['p256dh'] ?? ''),
                ':auth' => (string) ($input['authSecret'] ?? $input['auth'] ?? ''),
                ':label' => mb_substr((string) ($input['deviceLabel'] ?? 'Browser'), 0, 120),
            ],
        )->queryScalar();

        return ['id' => $id, 'registered' => true];
    }

    public function revokePushSubscription(AuthenticatedIdentity $identity, string $organizationId, string $id): array
    {
        $this->assertAccess($identity, $organizationId);
        $subId = RealtimeService::assertUuid($id, 'id');
        $this->db->createCommand(
            'UPDATE notifications.push_subscriptions SET revoked_at = now()
             WHERE id = CAST(:id AS uuid) AND account_id = CAST(:account AS uuid)',
            [':id' => $subId, ':account' => $identity->accountId],
        )->execute();
        return ['revoked' => true];
    }

    private function assertAccess(AuthenticatedIdentity $identity, string $organizationId): string
    {
        $personId = $identity->personId;
        if ($personId === null || $personId === '') {
            throw new HttpError(403, 'access_denied');
        }
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        if (!$this->organizations->activeMember($personId, $orgId)) {
            throw new HttpError(403, 'organization_access_denied');
        }
        if (!$this->permissions->hasPermission($identity->accountId, 'notifications.use')) {
            throw new HttpError(403, 'access_denied');
        }
        return $personId;
    }
}
