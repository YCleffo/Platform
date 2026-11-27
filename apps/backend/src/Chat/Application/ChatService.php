<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Notifications\Application\NotificationsService;
use App\Realtime\Application\RealtimeEvent;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Universal multi-tenant Chat Engine supporting direct, group, organization, and domain-context rooms,
 * editing, per-user/for-all deletion, forwarding, pinning, drafts sync, search, reactions, receipts, and Realtime delivery.
 */
final readonly class ChatService
{
    public function __construct(
        private ConnectionInterface $db,
        private OrganizationRepository $organizations,
        private PermissionService $permissions,
        private RealtimeService $realtime,
        private NotificationsService $notifications,
        private ChatContextPolicyInterface $contextPolicy,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listRooms(AuthenticatedIdentity $identity, string $organizationId, array $query = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $limit = max(1, min(100, isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 50));
        $offset = max(0, isset($query['offset']) && is_numeric($query['offset']) ? (int) $query['offset'] : 0);
        $tab = is_string($query['tab'] ?? null) ? $query['tab'] : 'all';
        $rawQ = $query['q'] ?? null;
        $search = is_string($rawQ) ? trim($rawQ) : '';
        $rawCtxType = $query['contextType'] ?? null;
        $contextType = is_string($rawCtxType) && $rawCtxType !== '' ? $rawCtxType : null;
        $rawCtxId = $query['contextId'] ?? null;
        $contextId = is_string($rawCtxId) && $rawCtxId !== ''
            ? RealtimeService::assertUuid($rawCtxId, 'contextId') : null;

        $where = 'r.organization_id = CAST(:org AS uuid) AND me.person_id = CAST(:person AS uuid) AND me.left_at IS NULL';
        $params = [':org' => $orgId, ':person' => $personId, ':limit' => $limit + 1, ':offset' => $offset];

        if ($tab === 'archived') {
            $where .= ' AND (me.archived_at IS NOT NULL OR r.archived_at IS NOT NULL)';
        } else {
            $where .= ' AND me.archived_at IS NULL AND r.archived_at IS NULL';
        }
        if (in_array($tab, ['direct', 'group', 'organization', 'context', 'support'], true)) {
            $where .= ' AND r.room_type = :roomType';
            $params[':roomType'] = $tab;
        }
        if ($contextType !== null) {
            $where .= ' AND r.context_type = :ctxType';
            $params[':ctxType'] = $contextType;
        }
        if ($contextId !== null) {
            $where .= ' AND r.context_id = CAST(:ctxId AS uuid)';
            $params[':ctxId'] = $contextId;
        }
        if ($search !== '') {
            $where .= " AND (
                r.title ILIKE :q
                OR EXISTS (
                    SELECT 1 FROM chat.members pm
                    JOIN core.persons pp ON pp.id = pm.person_id
                    WHERE pm.room_id = r.id AND pm.left_at IS NULL
                      AND (pp.display_name ILIKE :q OR pp.given_name ILIKE :q OR pp.family_name ILIKE :q)
                )
            )";
            $params[':q'] = '%' . $search . '%';
        }

        $unreadFilter = $tab === 'unread' ? ' AND unread.cnt > 0' : '';

        $rows = $this->db->createCommand(
            "SELECT
                r.id::text,
                r.room_type AS \"roomType\",
                r.title,
                r.title AS subject,
                r.description,
                r.context_type AS \"contextType\",
                r.context_id::text AS \"contextId\",
                r.pinned_message_id::text AS \"pinnedMessageId\",
                r.last_activity_at::text AS \"lastActivityAt\",
                r.created_at::text AS \"createdAt\",
                me.role AS \"myRole\",
                me.can_write AS \"canWrite\",
                me.muted_until::text AS \"mutedUntil\",
                me.archived_at::text AS \"archivedAt\",
                me.pinned_order AS \"pinnedOrder\",
                me.draft_text AS \"draftText\",
                me.draft_reply_to_id::text AS \"draftReplyToId\",
                COALESCE(mc.cnt, 0) AS \"memberCount\",
                peer.person_id AS \"peerPersonId\",
                peer.name AS \"peerName\",
                lm.body AS \"lastMessageBody\",
                lm.kind AS \"lastMessageKind\",
                lm.created_at AS \"lastMessageAt\",
                lm.sender_person_id AS \"lastMessageSenderId\",
                COALESCE(unread.cnt, 0) AS \"unreadCount\"
             FROM chat.rooms r
             JOIN chat.members me ON me.room_id = r.id AND me.organization_id = r.organization_id
             LEFT JOIN LATERAL (
                SELECT count(*)::int AS cnt
                FROM chat.members m2
                WHERE m2.room_id = r.id AND m2.left_at IS NULL
             ) mc ON true
             LEFT JOIN LATERAL (
                SELECT
                    p2.person_id::text AS person_id,
                    COALESCE(NULLIF(p.display_name, ''), CONCAT_WS(' ', p.given_name, p.family_name)) AS name
                FROM chat.members p2
                JOIN core.persons p ON p.id = p2.person_id
                WHERE p2.room_id = r.id AND p2.left_at IS NULL AND p2.person_id <> CAST(:person AS uuid)
                ORDER BY p2.joined_at ASC
                LIMIT 1
             ) peer ON true
             LEFT JOIN LATERAL (
                SELECT lm2.body, lm2.kind, lm2.created_at::text AS created_at, lm2.sender_person_id::text AS sender_person_id
                FROM chat.messages lm2
                WHERE lm2.room_id = r.id
                  AND lm2.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM chat.message_deletions md WHERE md.message_id = lm2.id AND md.person_id = CAST(:person AS uuid))
                ORDER BY lm2.created_at DESC, lm2.id DESC
                LIMIT 1
             ) lm ON true
             LEFT JOIN LATERAL (
                SELECT count(*)::int AS cnt
                FROM chat.messages m
                WHERE m.room_id = r.id
                  AND m.deleted_at IS NULL
                  AND m.sender_person_id <> CAST(:person AS uuid)
                  AND NOT EXISTS (SELECT 1 FROM chat.message_deletions md WHERE md.message_id = m.id AND md.person_id = CAST(:person AS uuid))
                  AND (me.last_read_at IS NULL OR (m.created_at, m.id) > (me.last_read_at, COALESCE(me.last_read_message_id, 'ffffffff-ffff-ffff-ffff-ffffffffffff'::uuid)))
             ) unread ON true
             WHERE {$where} {$unreadFilter}
             ORDER BY (me.pinned_order IS NOT NULL) DESC, me.pinned_order ASC NULLS LAST, r.last_activity_at DESC, r.id DESC
             LIMIT :limit OFFSET :offset",
            $params,
        )->queryAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        // Batch hydrate peer online presence from Redis
        $peerIds = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            if (!empty($r['peerPersonId']) && is_string($r['peerPersonId'])) {
                $peerIds[] = $r['peerPersonId'];
            }
        }
        $presences = [];
        foreach ($this->realtime->getPresences($orgId, $peerIds) as $p) {
            $presences[$p['personId']] = $p;
        }
        foreach ($rows as &$r) {
            $rawPeer = $r['peerPersonId'] ?? null;
            $pid = is_string($rawPeer) ? $rawPeer : null;
            $r['peerPresence'] = $pid !== null && isset($presences[$pid])
                ? $presences[$pid]
                : ($pid !== null ? ['personId' => $pid, 'status' => 'offline', 'lastSeenAt' => null] : null);
        }
        unset($r);

        return ['items' => $rows, 'hasMore' => $hasMore, 'offset' => $offset];
    }

    /** @return array<string, mixed> */
    public function getRoom(AuthenticatedIdentity $identity, string $organizationId, string $roomId): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');

        $room = $this->db->createCommand(
            'SELECT
                r.id::text, r.room_type AS "roomType", r.title, r.title AS subject, r.description,
                r.context_type AS "contextType", r.context_id::text AS "contextId",
                r.pinned_message_id::text AS "pinnedMessageId",
                r.last_activity_at::text AS "lastActivityAt", r.created_at::text AS "createdAt",
                me.role AS "myRole", me.can_write AS "canWrite",
                me.muted_until::text AS "mutedUntil", me.archived_at::text AS "archivedAt",
                me.pinned_order AS "pinnedOrder", me.draft_text AS "draftText",
                me.draft_reply_to_id::text AS "draftReplyToId"
             FROM chat.rooms r
             JOIN chat.members me ON me.room_id = r.id AND me.organization_id = r.organization_id
             WHERE r.id = CAST(:room AS uuid)
               AND r.organization_id = CAST(:org AS uuid)
               AND me.person_id = CAST(:person AS uuid)
               AND me.left_at IS NULL',
            [':room' => $rid, ':org' => $orgId, ':person' => $personId],
        )->queryOne();

        if (!is_array($room)) {
            throw new HttpError(404, 'room_not_found');
        }

        $members = $this->db->createCommand(
            "SELECT
                m.person_id::text AS \"personId\",
                COALESCE(NULLIF(p.display_name, ''), NULLIF(CONCAT_WS(' ', p.given_name, p.family_name), ''), 'Member') AS name,
                m.role,
                m.can_write AS \"canWrite\",
                m.last_read_at::text AS \"lastReadAt\",
                m.joined_at::text AS \"joinedAt\"
             FROM chat.members m
             JOIN core.persons p ON p.id = m.person_id
             WHERE m.room_id = CAST(:room AS uuid)
               AND m.organization_id = CAST(:org AS uuid)
               AND m.left_at IS NULL
             ORDER BY m.role = 'owner' DESC, name ASC",
            [':room' => $rid, ':org' => $orgId],
        )->queryAll();

        $presences = [];
        foreach ($this->realtime->getPresences($orgId, array_map(static fn(array $m): string => (string) $m['personId'], $members)) as $p) {
            $presences[$p['personId']] = $p;
        }
        foreach ($members as &$m) {
            $pid = (string) $m['personId'];
            $m['presence'] = $presences[$pid] ?? ['personId' => $pid, 'status' => 'offline', 'lastSeenAt' => null];
        }
        unset($m);

        $room['members'] = $members;
        $room['pinnedMessage'] = null;
        if (!empty($room['pinnedMessageId'])) {
            $pinned = $this->db->createCommand(
                'SELECT id::text, sender_person_id::text AS "senderPersonId", body, kind, created_at::text AS "createdAt"
                 FROM chat.messages
                 WHERE id = CAST(:id AS uuid) AND deleted_at IS NULL',
                [':id' => (string) $room['pinnedMessageId']],
            )->queryOne();
            if (is_array($pinned)) {
                $room['pinnedMessage'] = $pinned;
            }
        }

        return $room;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createRoom(AuthenticatedIdentity $identity, string $organizationId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');

        $roomType = is_string($input['roomType'] ?? null) ? $input['roomType'] : 'direct';
        if (!in_array($roomType, ['direct', 'group', 'organization', 'support', 'context'], true)) {
            throw new HttpError(422, 'invalid_room_type');
        }

        $requiredPerm = match ($roomType) {
            'direct', 'support', 'context' => 'chat.rooms.direct',
            'group' => 'chat.rooms.group',
            'organization' => 'chat.rooms.organization',
        };
        if (!$this->permissions->hasPermission($identity->accountId, $requiredPerm)) {
            throw new HttpError(403, 'access_denied');
        }

        $rawInputMembers = $input['memberPersonIds'] ?? null;
        /** @var list<mixed> $rawMembers */
        $rawMembers = is_array($rawInputMembers) ? array_values($rawInputMembers) : [];
        if (isset($input['recipientPersonId']) && is_string($input['recipientPersonId']) && $input['recipientPersonId'] !== '') {
            $rawMembers[] = $input['recipientPersonId'];
        }
        $memberPersonIds = [];
        foreach ($rawMembers as $mid) {
            $uuid = RealtimeService::assertUuid(is_scalar($mid) ? (string) $mid : '', 'memberPersonId');
            if ($uuid !== $personId) {
                $memberPersonIds[] = $uuid;
            }
        }
        $memberPersonIds = array_values(array_unique($memberPersonIds));

        if (in_array($roomType, ['direct', 'support', 'context'], true) && count($memberPersonIds) === 0) {
            throw new HttpError(422, 'recipient_required');
        }
        if ($roomType === 'direct' && count($memberPersonIds) > 1) {
            $roomType = 'group';
        }

        $rawCtxType = $input['contextType'] ?? null;
        $contextType = is_string($rawCtxType) && trim($rawCtxType) !== ''
            ? mb_substr(trim($rawCtxType), 0, 40) : null;
        $rawCtxId = $input['contextId'] ?? null;
        $contextId = is_string($rawCtxId) && $rawCtxId !== ''
            ? RealtimeService::assertUuid($rawCtxId, 'contextId') : null;

        if (!$this->contextPolicy->canCreateRoomWith($identity, $orgId, $roomType, $memberPersonIds, $contextType, $contextId)) {
            throw new HttpError(403, 'chat_context_denied');
        }

        // Reuse existing 1:1 direct room for same participants unless forceNew
        if ($roomType === 'direct' && count($memberPersonIds) === 1 && empty($input['forceNew'])) {
            $peerId = $memberPersonIds[0];
            $existingId = $this->db->createCommand(
                "SELECT r.id::text
                 FROM chat.rooms r
                 JOIN chat.members m1 ON m1.room_id = r.id AND m1.person_id = CAST(:p1 AS uuid) AND m1.left_at IS NULL
                 JOIN chat.members m2 ON m2.room_id = r.id AND m2.person_id = CAST(:p2 AS uuid) AND m2.left_at IS NULL
                 WHERE r.organization_id = CAST(:org AS uuid)
                   AND r.room_type = 'direct'
                   AND r.archived_at IS NULL
                   AND (SELECT count(*) FROM chat.members mc WHERE mc.room_id = r.id AND mc.left_at IS NULL) = 2
                 ORDER BY r.last_activity_at DESC LIMIT 1",
                [':org' => $orgId, ':p1' => $personId, ':p2' => $peerId],
            )->queryScalar();
            if (is_string($existingId)) {
                $initialBody = trim((string) ($input['body'] ?? $input['initialMessage'] ?? ''));
                if ($initialBody !== '') {
                    $this->sendMessage($identity, $orgId, $existingId, [
                        'body' => $initialBody,
                        'clientKey' => $input['clientKey'] ?? null,
                    ]);
                }
                return $this->getRoom($identity, $orgId, $existingId);
            }
        }

        $title = trim((string) ($input['title'] ?? $input['subject'] ?? ''));
        if ($title === '') {
            $title = $roomType === 'organization' ? 'Organization channel' : 'Direct chat';
        }
        $title = mb_substr($title, 0, 180);
        $description = trim((string) ($input['description'] ?? ''));
        $initialBody = trim((string) ($input['body'] ?? $input['initialMessage'] ?? ''));

        $allMembers = array_values(array_unique([$personId, ...$memberPersonIds]));
        // For org channels without explicit member list, enroll active org members (capped at 500)
        if ($roomType === 'organization' && count($allMembers) === 1 && !empty($input['includeAllOrgMembers'])) {
            $orgMembers = $this->db->createCommand(
                "SELECT person_id::text FROM core.organization_memberships
                 WHERE organization_id = CAST(:org AS uuid) AND status = 'active' LIMIT 500",
                [':org' => $orgId],
            )->queryColumn();
            foreach ($orgMembers as $om) {
                if (is_string($om)) {
                    $allMembers[] = $om;
                }
            }
            $allMembers = array_values(array_unique($allMembers));
        }

        $roomId = '';
        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (
            &$roomId,
            &$publishedEvents,
            $identity,
            $orgId,
            $personId,
            $roomType,
            $title,
            $description,
            $contextType,
            $contextId,
            $allMembers,
        ): void {
            $roomId = (string) $this->db->createCommand(
                'INSERT INTO chat.rooms (
                    organization_id, room_type, title, description, context_type, context_id,
                    created_by_person_id, created_by_account_id, last_activity_at
                 ) VALUES (
                    CAST(:org AS uuid), :type, :title, :desc, :ctxType, CAST(:ctxId AS uuid),
                    CAST(:creatorPerson AS uuid), CAST(:creatorAccount AS uuid), now()
                 ) RETURNING id::text',
                [
                    ':org' => $orgId,
                    ':type' => $roomType,
                    ':title' => $title,
                    ':desc' => $description,
                    ':ctxType' => $contextType,
                    ':ctxId' => $contextId,
                    ':creatorPerson' => $personId,
                    ':creatorAccount' => $identity->accountId,
                ],
            )->queryScalar();

            foreach ($allMembers as $memberPid) {
                $role = $memberPid === $personId ? 'owner' : 'member';
                $this->db->createCommand(
                    'INSERT INTO chat.members (room_id, organization_id, person_id, role, can_write)
                     VALUES (CAST(:room AS uuid), CAST(:org AS uuid), CAST(:person AS uuid), :role, true)
                     ON CONFLICT (room_id, person_id) DO UPDATE SET left_at = NULL, role = EXCLUDED.role',
                    [':room' => $roomId, ':org' => $orgId, ':person' => $memberPid, ':role' => $role],
                )->execute();
            }

            $specs = [];
            foreach ($allMembers as $memberPid) {
                $specs[] = [
                    'organizationId' => $orgId,
                    'channel' => 'user:' . $memberPid,
                    'eventType' => 'chat.room.created',
                    'payload' => [
                        'roomId' => $roomId,
                        'roomType' => $roomType,
                        'title' => $title,
                        'contextType' => $contextType,
                        'contextId' => $contextId,
                    ],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $personId,
                    'targetPersonIds' => [$memberPid],
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $roomId,
                ];
            }
            $publishedEvents = $this->realtime->publishBatch($specs);
        });

        $this->realtime->tryPushEvents($publishedEvents);

        if ($initialBody !== '') {
            $this->sendMessage($identity, $orgId, $roomId, [
                'body' => $initialBody,
                'clientKey' => $input['clientKey'] ?? null,
            ]);
        }

        return $this->getRoom($identity, $orgId, $roomId);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listMessages(AuthenticatedIdentity $identity, string $organizationId, string $roomId, array $query = []): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $this->assertRoomMember($orgId, $rid, $personId);

        $limit = max(1, min(100, isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : 50));
        $offset = max(0, isset($query['offset']) && is_numeric($query['offset']) ? (int) $query['offset'] : 0);
        $beforeSeq = isset($query['beforeSeq']) && is_numeric($query['beforeSeq']) ? (int) $query['beforeSeq'] : null;
        $rawAround = $query['aroundMessageId'] ?? null;
        $aroundMessageId = is_string($rawAround) && $rawAround !== ''
            ? RealtimeService::assertUuid($rawAround, 'aroundMessageId') : null;

        $where = 'm.room_id = CAST(:room AS uuid) AND m.organization_id = CAST(:org AS uuid)
                  AND NOT EXISTS (SELECT 1 FROM chat.message_deletions md WHERE md.message_id = m.id AND md.person_id = CAST(:person AS uuid))';
        $params = [':room' => $rid, ':org' => $orgId, ':person' => $personId, ':limit' => $limit + 1, ':offset' => $offset];
        if ($aroundMessageId !== null) {
            $targetSeq = $this->db->createCommand(
                'SELECT seq FROM chat.messages WHERE id = CAST(:mid AS uuid) AND room_id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)',
                [':mid' => $aroundMessageId, ':room' => $rid, ':org' => $orgId],
            )->queryScalar();
            if ($targetSeq !== false && is_numeric($targetSeq)) {
                $half = (int) floor($limit / 2);
                $where .= ' AND m.seq <= :aroundMaxSeq';
                $params[':aroundMaxSeq'] = (int) $targetSeq + $half;
                $params[':offset'] = 0;
            }
        } elseif ($beforeSeq !== null && $beforeSeq > 0) {
            $where .= ' AND m.seq < :beforeSeq';
            $params[':beforeSeq'] = $beforeSeq;
            $params[':offset'] = 0;
        }

        $rows = $this->db->createCommand(
            "SELECT
                m.id::text,
                m.room_id::text AS \"roomId\",
                m.seq,
                m.sender_person_id::text AS \"senderPersonId\",
                COALESCE(NULLIF(sp.display_name, ''), NULLIF(CONCAT_WS(' ', sp.given_name, sp.family_name), ''), 'User') AS \"senderName\",
                CASE WHEN m.deleted_at IS NOT NULL THEN NULL ELSE m.body END AS body,
                m.kind,
                m.media_asset_id::text AS \"mediaAssetId\",
                m.reply_to_id::text AS \"replyToId\",
                m.forwarded_from_message_id::text AS \"forwardedFromMessageId\",
                m.forwarded_from_room_id::text AS \"forwardedFromRoomId\",
                m.metadata::text AS \"metadataJson\",
                m.edited_at::text AS \"editedAt\",
                m.deleted_at::text AS \"deletedAt\",
                m.deleted_for_all AS \"deletedForAll\",
                m.pinned_at::text AS \"pinnedAt\",
                m.created_at::text AS \"createdAt\",
                ma.kind AS \"mediaKind\",
                ma.original_name AS \"mediaName\",
                COALESCE(ma.verified_mime, ma.original_mime) AS \"mediaMime\",
                ma.size_bytes AS \"mediaSize\",
                ma.duration_seconds AS \"mediaDuration\",
                ma.status AS \"mediaStatus\",
                rm.body AS \"replyBody\",
                rm.sender_person_id::text AS \"replySenderPersonId\",
                EXISTS (
                    SELECT 1 FROM chat.delivery_receipts dr
                    WHERE dr.message_id = m.id AND dr.person_id <> m.sender_person_id AND dr.read_at IS NOT NULL
                ) AS \"isReadByPeer\"
             FROM chat.messages m
             LEFT JOIN core.persons sp ON sp.id = m.sender_person_id
             LEFT JOIN media.assets ma ON ma.id = m.media_asset_id
             LEFT JOIN chat.messages rm ON rm.id = m.reply_to_id
             WHERE {$where}
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT :limit OFFSET :offset",
            $params,
        )->queryAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        if ($rows === []) {
            return ['items' => [], 'hasMore' => false, 'offset' => $offset];
        }

        $msgIds = array_map(static fn(array $r): string => (string) $r['id'], $rows);
        $reactionsRows = $this->db->createCommand(
            'SELECT r.message_id::text AS "messageId", r.emoji, count(*)::int AS count,
                    bool_or(r.person_id = CAST(:person AS uuid)) AS reacted
             FROM chat.reactions r
             JOIN chat.messages m ON m.id = r.message_id
             WHERE m.room_id = CAST(:room AS uuid) AND m.organization_id = CAST(:org AS uuid)
             GROUP BY r.message_id, r.emoji
             ORDER BY count DESC, r.emoji',
            [':room' => $rid, ':org' => $orgId, ':person' => $personId],
        )->queryAll();

        /** @var array<string, list<array{emoji: string, count: int, reacted: bool}>> $byMsg */
        $byMsg = [];
        /** @var array<string, mixed> $rr */
        foreach ($reactionsRows as $rr) {
            $mid = (string) ($rr['messageId'] ?? '');
            if ($mid !== '' && in_array($mid, $msgIds, true)) {
                $byMsg[$mid][] = [
                    'emoji' => (string) $rr['emoji'],
                    'count' => (int) $rr['count'],
                    'reacted' => (bool) $rr['reacted'],
                ];
            }
        }

        $items = [];
        /** @var array<string, mixed> $row */
        foreach ($rows as $row) {
            $items[] = $this->formatMessageRow($row, $byMsg[(string) $row['id']] ?? []);
        }

        return ['items' => $items, 'hasMore' => $hasMore, 'offset' => $offset];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sendMessage(AuthenticatedIdentity $identity, string $organizationId, string $roomId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $member = $this->assertRoomMember($orgId, $rid, $personId);

        if (!$member['canWrite']) {
            throw new HttpError(403, 'room_read_only');
        }

        $rawKind = $input['kind'] ?? null;
        $kind = is_string($rawKind) && trim($rawKind) !== '' ? trim($rawKind) : 'text';
        $allowedKinds = ['text', 'voice', 'video_note', 'video', 'audio', 'image', 'file', 'activity_card', 'assessment_card', 'homework_card', 'poll', 'system'];
        if (!in_array($kind, $allowedKinds, true)) {
            throw new HttpError(422, 'invalid_message_kind');
        }

        $rawBody = $input['body'] ?? null;
        $body = is_string($rawBody) ? trim($rawBody) : null;
        if ($body === '') {
            $body = null;
        }
        $rawMediaId = $input['mediaAssetId'] ?? null;
        $mediaAssetId = is_string($rawMediaId) && $rawMediaId !== ''
            ? RealtimeService::assertUuid($rawMediaId, 'mediaAssetId') : null;
        $rawReplyId = $input['replyToId'] ?? null;
        $replyToId = is_string($rawReplyId) && $rawReplyId !== ''
            ? RealtimeService::assertUuid($rawReplyId, 'replyToId') : null;
        $rawFwdId = $input['forwardedFromMessageId'] ?? null;
        $forwardedMsgId = is_string($rawFwdId) && $rawFwdId !== ''
            ? RealtimeService::assertUuid($rawFwdId, 'forwardedFromMessageId') : null;
        $rawCk = $input['clientKey'] ?? null;
        $clientKey = is_string($rawCk) && trim($rawCk) !== ''
            ? mb_substr(trim($rawCk), 0, 128) : null;

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        unset($metadata['playbackUrl'], $metadata['streamUrl'], $metadata['url']);

        if ($forwardedMsgId !== null) {
            $src = $this->db->createCommand(
                'SELECT m.id::text, m.room_id::text AS "roomId", m.body, m.kind, m.media_asset_id::text AS "mediaAssetId",
                        m.metadata::text AS "metadataJson"
                 FROM chat.messages m
                 JOIN chat.members srcm ON srcm.room_id = m.room_id AND srcm.person_id = CAST(:person AS uuid) AND srcm.left_at IS NULL
                 WHERE m.id = CAST(:id AS uuid) AND m.organization_id = CAST(:org AS uuid) AND m.deleted_at IS NULL',
                [':id' => $forwardedMsgId, ':org' => $orgId, ':person' => $personId],
            )->queryOne();
            if (!is_array($src)) {
                throw new HttpError(404, 'forwarded_message_not_found');
            }
            $rawSrcBody = $src['body'] ?? null;
            if ($body === null && is_string($rawSrcBody) && $rawSrcBody !== '') {
                $body = $rawSrcBody;
            }
            if ($mediaAssetId === null && !empty($src['mediaAssetId'])) {
                $mediaAssetId = (string) $src['mediaAssetId'];
                $kind = (string) ($src['kind'] ?? $kind);
            }
            $forwardedRoomId = (string) $src['roomId'];
        } else {
            $forwardedRoomId = null;
        }

        $cardKinds = ['activity_card', 'assessment_card', 'homework_card', 'poll', 'system'];
        if ($body === null && $mediaAssetId === null && !in_array($kind, $cardKinds, true)) {
            throw new HttpError(422, 'empty_message');
        }
        $bodyText = is_string($body) ? $body : null;
        if ($bodyText !== null && mb_strlen($bodyText) > 10000) {
            throw new HttpError(422, 'message_too_long');
        }
        $body = $bodyText;

        if ($mediaAssetId !== null && $forwardedMsgId === null) {
            $mediaValid = $this->db->createCommand(
                "SELECT 1 FROM media.assets
                 WHERE id = CAST(:mId AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND owner_person_id = CAST(:person AS uuid)
                   AND status = 'READY'",
                [':mId' => $mediaAssetId, ':org' => $orgId, ':person' => $personId],
            )->queryScalar();
            if ($mediaValid === false) {
                throw new HttpError(403, 'media_asset_unavailable');
            }
        }

        if ($replyToId !== null) {
            $replyExists = $this->db->createCommand(
                'SELECT 1 FROM chat.messages WHERE id = CAST(:id AS uuid) AND room_id = CAST(:room AS uuid)',
                [':id' => $replyToId, ':room' => $rid],
            )->queryScalar();
            if ($replyExists === false) {
                throw new HttpError(404, 'reply_target_not_found');
            }
        }

        $formatted = null;
        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (
            &$formatted,
            &$publishedEvents,
            $identity,
            $orgId,
            $rid,
            $personId,
            $body,
            $clientKey,
            $kind,
            $mediaAssetId,
            $replyToId,
            $forwardedMsgId,
            $forwardedRoomId,
            $metadata,
        ): void {
            if ($clientKey !== null) {
                $existing = $this->db->createCommand(
                    'SELECT id::text FROM chat.messages
                     WHERE room_id = CAST(:room AS uuid) AND sender_person_id = CAST(:person AS uuid) AND client_key = :ck',
                    [':room' => $rid, ':person' => $personId, ':ck' => $clientKey],
                )->queryScalar();
                if (is_string($existing)) {
                    $formatted = $this->fetchSingleMessage($orgId, $rid, $personId, $existing);
                    return;
                }
            }

            $inserted = $this->db->createCommand(
                'INSERT INTO chat.messages (
                    room_id, organization_id, sender_person_id, sender_account_id, kind, body,
                    media_asset_id, reply_to_id, forwarded_from_message_id, forwarded_from_room_id,
                    metadata, client_key
                ) VALUES (
                    CAST(:room AS uuid), CAST(:org AS uuid), CAST(:person AS uuid), CAST(:account AS uuid), :kind, :body,
                    CAST(:media AS uuid), CAST(:reply AS uuid), CAST(:fwdMsg AS uuid), CAST(:fwdRoom AS uuid),
                    CAST(:meta AS jsonb), :ck
                ) RETURNING id::text, seq, created_at::text AS "createdAt"',
                [
                    ':room' => $rid,
                    ':org' => $orgId,
                    ':person' => $personId,
                    ':account' => $identity->accountId,
                    ':kind' => $kind,
                    ':body' => $body,
                    ':media' => $mediaAssetId,
                    ':reply' => $replyToId,
                    ':fwdMsg' => $forwardedMsgId,
                    ':fwdRoom' => $forwardedRoomId,
                    ':meta' => $metadata === [] ? '{}' : json_encode($metadata, JSON_THROW_ON_ERROR),
                    ':ck' => $clientKey,
                ],
            )->queryOne();

            if (!is_array($inserted)) {
                throw new \RuntimeException('Failed to insert chat message.');
            }
            $insertedId = (string) $inserted['id'];

            if ($mediaAssetId === null && $replyToId === null) {
                $senderNameRaw = $this->db->createCommand(
                    "SELECT COALESCE(NULLIF(display_name, ''), NULLIF(CONCAT_WS(' ', given_name, family_name), ''), 'User')
                     FROM core.persons WHERE id = CAST(:person AS uuid)",
                    [':person' => $personId],
                )->queryScalar();
                $senderName = is_string($senderNameRaw) && $senderNameRaw !== '' ? $senderNameRaw : 'User';
                $formatted = [
                    'id' => $insertedId,
                    'roomId' => $rid,
                    'seq' => (int) ($inserted['seq'] ?? 0),
                    'senderPersonId' => $personId,
                    'senderName' => $senderName,
                    'body' => $body,
                    'kind' => $kind,
                    'mediaAssetId' => null,
                    'replyToId' => null,
                    'forwardedFromMessageId' => $forwardedMsgId,
                    'forwardedFromRoomId' => $forwardedRoomId,
                    'editedAt' => null,
                    'deletedAt' => null,
                    'deletedForAll' => false,
                    'pinnedAt' => null,
                    'createdAt' => (string) ($inserted['createdAt'] ?? ''),
                    'isReadByPeer' => false,
                    'reactions' => [],
                    'media' => null,
                    'replyTo' => null,
                    'metadata' => $metadata,
                ];
            } else {
                $formatted = $this->fetchSingleMessage($orgId, $rid, $personId, $insertedId);
            }

            $this->db->createCommand(
                'UPDATE chat.members SET draft_text = NULL, draft_reply_to_id = NULL, draft_updated_at = NULL
                 WHERE room_id = CAST(:room AS uuid) AND person_id = CAST(:person AS uuid)
                   AND (draft_text IS NOT NULL OR draft_reply_to_id IS NOT NULL)',
                [':room' => $rid, ':person' => $personId],
            )->execute();

            $roomMeta = $this->db->createCommand(
                'SELECT title AS "roomTitle" FROM chat.rooms
                 WHERE id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)',
                [':room' => $rid, ':org' => $orgId],
            )->queryScalar();
            $roomTitle = is_string($roomMeta) && $roomMeta !== '' ? $roomMeta : 'New message';

            $peerIdsRaw = $this->db->createCommand(
                'SELECT person_id::text FROM chat.members
                 WHERE room_id = CAST(:room AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND left_at IS NULL
                   AND person_id <> CAST(:person AS uuid)',
                [':room' => $rid, ':org' => $orgId, ':person' => $personId],
            )->queryColumn();

            $peerIds = [];
            foreach ($peerIdsRaw as $rawPid) {
                if (is_string($rawPid) && $rawPid !== '') {
                    $peerIds[] = $rawPid;
                }
            }

            $eventSpecs = [
                [
                    'organizationId' => $orgId,
                    'channel' => 'room:' . $rid,
                    'eventType' => 'chat.message.created',
                    'payload' => ['roomId' => $rid, 'message' => $formatted],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $personId,
                    'targetPersonIds' => null,
                    'aggregateType' => 'chat_message',
                    'aggregateId' => $insertedId,
                ],
            ];
            foreach ($peerIds as $peerId) {
                $eventSpecs[] = [
                    'organizationId' => $orgId,
                    'channel' => 'user:' . $peerId,
                    'eventType' => 'chat.room.updated',
                    'payload' => [
                        'roomId' => $rid,
                        'lastMessage' => $formatted,
                        'unreadIncrement' => 1,
                    ],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $personId,
                    'targetPersonIds' => [$peerId],
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $rid,
                ];
            }
            $publishedEvents = $this->realtime->publishBatch($eventSpecs);

            if ($peerIds !== []) {
                $previewText = $body !== null ? mb_substr($body, 0, 140) : '[' . $kind . ']';
                $notifEvents = $this->notifications->notifyBatch(
                    organizationId: $orgId,
                    personIds: $peerIds,
                    category: 'message',
                    kind: 'message',
                    title: $roomTitle,
                    body: $previewText,
                    roomId: $rid,
                    messageId: $insertedId,
                    resourceType: 'chat_room',
                    resourceId: $rid,
                    resourcePath: '/messages/' . $rid,
                    payload: ['roomId' => $rid, 'messageId' => $insertedId, 'senderPersonId' => $personId],
                );
                foreach ($notifEvents as $ne) {
                    $publishedEvents[] = $ne;
                }
            }

            // Update room summary last to minimize exclusive row lock hold time on chat.rooms
            $this->db->createCommand(
                'UPDATE chat.rooms SET last_activity_at = now(), last_message_id = CAST(:mid AS uuid), updated_at = now()
                 WHERE id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)',
                [':mid' => $insertedId, ':room' => $rid, ':org' => $orgId],
            )->execute();
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return $formatted ?? [];
    }

    /** @return array<string, mixed> */
    public function editMessage(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomId,
        string $messageId,
        string $newBody,
    ): array {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $mid = RealtimeService::assertUuid($messageId, 'messageId');
        $this->assertRoomMember($orgId, $rid, $personId);

        $trimmed = trim($newBody);
        if ($trimmed === '' || mb_strlen($trimmed) > 10000) {
            throw new HttpError(422, 'invalid_message_body');
        }

        $updated = null;
        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (&$updated, &$publishedEvents, $identity, $orgId, $rid, $mid, $personId, $trimmed): void {
            $existing = $this->db->createCommand(
                'SELECT sender_person_id::text AS "senderPersonId", body, deleted_at
                 FROM chat.messages
                 WHERE id = CAST(:id AS uuid) AND room_id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)
                 FOR UPDATE',
                [':id' => $mid, ':room' => $rid, ':org' => $orgId],
            )->queryOne();

            if (!is_array($existing) || $existing['deleted_at'] !== null) {
                throw new HttpError(404, 'message_not_found');
            }
            if ((string) $existing['senderPersonId'] !== $personId) {
                throw new HttpError(403, 'cannot_edit_foreign_message');
            }

            $this->db->createCommand(
                'INSERT INTO chat.message_edits (message_id, editor_person_id, previous_body, new_body)
                 VALUES (CAST(:mid AS uuid), CAST(:person AS uuid), :prev, :next)',
                [':mid' => $mid, ':person' => $personId, ':prev' => $existing['body'], ':next' => $trimmed],
            )->execute();

            $this->db->createCommand(
                'UPDATE chat.messages SET body = :body, edited_at = now(), updated_at = now()
                 WHERE id = CAST(:mid AS uuid)',
                [':body' => $trimmed, ':mid' => $mid],
            )->execute();

            $updated = $this->fetchSingleMessage($orgId, $rid, $personId, $mid);
            $publishedEvents[] = $this->realtime->publish(
                organizationId: $orgId,
                channel: 'room:' . $rid,
                eventType: 'chat.message.updated',
                payload: ['roomId' => $rid, 'message' => $updated],
                actorAccountId: $identity->accountId,
                actorPersonId: $personId,
                aggregateType: 'chat_message',
                aggregateId: $mid,
            );
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return $updated ?? [];
    }

    /** @return array{deleted: true, mode: string} */
    public function deleteMessage(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomId,
        string $messageId,
        string $mode = 'for_me',
    ): array {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $mid = RealtimeService::assertUuid($messageId, 'messageId');
        $member = $this->assertRoomMember($orgId, $rid, $personId);
        $deleteMode = $mode === 'for_all' ? 'for_all' : 'for_me';

        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (&$publishedEvents, $identity, $orgId, $rid, $mid, $personId, $member, $deleteMode): void {
            $msg = $this->db->createCommand(
                'SELECT sender_person_id::text AS "senderPersonId" FROM chat.messages
                 WHERE id = CAST(:id AS uuid) AND room_id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)',
                [':id' => $mid, ':room' => $rid, ':org' => $orgId],
            )->queryOne();
            if (!is_array($msg)) {
                throw new HttpError(404, 'message_not_found');
            }

            if ($deleteMode === 'for_all') {
                $isModerator = in_array($member['role'], ['owner', 'admin', 'moderator'], true);
                if ((string) $msg['senderPersonId'] !== $personId && !$isModerator) {
                    throw new HttpError(403, 'cannot_delete_for_all');
                }
                $this->db->createCommand(
                    'UPDATE chat.messages SET deleted_at = now(), deleted_for_all = true, body = NULL, updated_at = now()
                     WHERE id = CAST(:mid AS uuid)',
                    [':mid' => $mid],
                )->execute();
                $publishedEvents[] = $this->realtime->publish(
                    organizationId: $orgId,
                    channel: 'room:' . $rid,
                    eventType: 'chat.message.deleted',
                    payload: ['roomId' => $rid, 'messageId' => $mid, 'mode' => 'for_all'],
                    actorAccountId: $identity->accountId,
                    actorPersonId: $personId,
                    aggregateType: 'chat_message',
                    aggregateId: $mid,
                );
            } else {
                $this->db->createCommand(
                    'INSERT INTO chat.message_deletions (message_id, person_id) VALUES (CAST(:mid AS uuid), CAST(:person AS uuid))
                     ON CONFLICT (message_id, person_id) DO NOTHING',
                    [':mid' => $mid, ':person' => $personId],
                )->execute();
                $publishedEvents[] = $this->realtime->publish(
                    organizationId: $orgId,
                    channel: 'user:' . $personId,
                    eventType: 'chat.message.deleted',
                    payload: ['roomId' => $rid, 'messageId' => $mid, 'mode' => 'for_me'],
                    actorAccountId: $identity->accountId,
                    actorPersonId: $personId,
                    targetPersonIds: [$personId],
                    aggregateType: 'chat_message',
                    aggregateId: $mid,
                );
            }
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return ['deleted' => true, 'mode' => $deleteMode];
    }

    /** @return array{reacted: bool, reactions: list<array{emoji: string, count: int, reacted: bool}>} */
    public function toggleReaction(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomId,
        string $messageId,
        string $emoji,
    ): array {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $mid = RealtimeService::assertUuid($messageId, 'messageId');
        $this->assertRoomMember($orgId, $rid, $personId);

        $cleanEmoji = trim($emoji);
        if ($cleanEmoji === '' || mb_strlen($cleanEmoji) > 16) {
            throw new HttpError(422, 'invalid_emoji');
        }

        $result = ['reacted' => false, 'reactions' => []];
        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (&$result, &$publishedEvents, $identity, $orgId, $rid, $mid, $personId, $cleanEmoji): void {
            $existsMsg = $this->db->createCommand(
                'SELECT 1 FROM chat.messages WHERE id = CAST(:id AS uuid) AND room_id = CAST(:room AS uuid) AND deleted_at IS NULL',
                [':id' => $mid, ':room' => $rid],
            )->queryScalar();
            if ($existsMsg === false) {
                throw new HttpError(404, 'message_not_found');
            }

            $existing = $this->db->createCommand(
                'SELECT id::text FROM chat.reactions WHERE message_id = CAST(:mid AS uuid) AND person_id = CAST(:person AS uuid) AND emoji = :emoji',
                [':mid' => $mid, ':person' => $personId, ':emoji' => $cleanEmoji],
            )->queryScalar();

            if (is_string($existing)) {
                $this->db->createCommand('DELETE FROM chat.reactions WHERE id = CAST(:id AS uuid)', [':id' => $existing])->execute();
                $reacted = false;
            } else {
                $this->db->createCommand(
                    'INSERT INTO chat.reactions (message_id, organization_id, person_id, emoji)
                     VALUES (CAST(:mid AS uuid), CAST(:org AS uuid), CAST(:person AS uuid), :emoji)',
                    [':mid' => $mid, ':org' => $orgId, ':person' => $personId, ':emoji' => $cleanEmoji],
                )->execute();
                $reacted = true;
            }

            $rows = $this->db->createCommand(
                'SELECT emoji, count(*)::int AS count, bool_or(person_id = CAST(:person AS uuid)) AS reacted
                 FROM chat.reactions WHERE message_id = CAST(:mid AS uuid) GROUP BY emoji ORDER BY count DESC, emoji',
                [':mid' => $mid, ':person' => $personId],
            )->queryAll();

            $reactions = array_map(static fn(array $r): array => [
                'emoji' => (string) $r['emoji'],
                'count' => (int) $r['count'],
                'reacted' => (bool) $r['reacted'],
            ], $rows);

            $result = ['reacted' => $reacted, 'reactions' => $reactions];
            $publishedEvents[] = $this->realtime->publish(
                organizationId: $orgId,
                channel: 'room:' . $rid,
                eventType: 'chat.reaction.updated',
                payload: ['roomId' => $rid, 'messageId' => $mid, 'reactions' => $reactions, 'actorPersonId' => $personId],
                actorAccountId: $identity->accountId,
                actorPersonId: $personId,
                aggregateType: 'chat_message',
                aggregateId: $mid,
            );
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return $result;
    }

    /** @return array{read: true, lastReadMessageId: ?string} */
    public function markRead(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomId,
        ?string $lastSeenMessageId = null,
    ): array {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $this->assertRoomMember($orgId, $rid, $personId);

        $msgId = $lastSeenMessageId !== null && $lastSeenMessageId !== ''
            ? RealtimeService::assertUuid($lastSeenMessageId, 'lastSeenMessageId') : null;

        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (&$publishedEvents, $identity, $orgId, $rid, $personId, &$msgId): void {
            if ($msgId === null) {
                $latest = $this->db->createCommand(
                    'SELECT id::text, created_at::text AS "createdAt" FROM chat.messages
                     WHERE room_id = CAST(:room AS uuid) AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1',
                    [':room' => $rid],
                )->queryOne();
                if (!is_array($latest)) {
                    return;
                }
                $msgId = (string) $latest['id'];
                $at = (string) $latest['createdAt'];
            } else {
                $target = $this->db->createCommand(
                    'SELECT id::text, created_at::text AS "createdAt" FROM chat.messages
                     WHERE id = CAST(:mid AS uuid) AND room_id = CAST(:room AS uuid)',
                    [':mid' => $msgId, ':room' => $rid],
                )->queryOne();
                if (!is_array($target)) {
                    throw new HttpError(404, 'message_not_found');
                }
                $at = (string) $target['createdAt'];
            }

            $this->db->createCommand(
                "UPDATE chat.members SET
                    last_read_message_id = CASE
                        WHEN last_read_at IS NULL OR (last_read_at, COALESCE(last_read_message_id, 'ffffffff-ffff-ffff-ffff-ffffffffffff'::uuid)) < (CAST(:at AS timestamptz), CAST(:mid AS uuid))
                        THEN CAST(:mid AS uuid) ELSE last_read_message_id END,
                    last_read_at = GREATEST(last_read_at, CAST(:at AS timestamptz))
                 WHERE room_id = CAST(:room AS uuid) AND person_id = CAST(:person AS uuid)",
                [':room' => $rid, ':person' => $personId, ':mid' => $msgId, ':at' => $at],
            )->execute();

            $this->db->createCommand(
                "INSERT INTO chat.delivery_receipts (message_id, person_id, status, delivered_at, read_at)
                 SELECT m.id, CAST(:person AS uuid), 'read', now(), now()
                 FROM chat.messages m
                 WHERE m.room_id = CAST(:room AS uuid)
                   AND m.sender_person_id <> CAST(:person AS uuid)
                   AND (m.created_at, m.id) <= (CAST(:at AS timestamptz), CAST(:mid AS uuid))
                 ON CONFLICT (message_id, person_id) DO UPDATE SET status = 'read', read_at = COALESCE(chat.delivery_receipts.read_at, now())",
                [':room' => $rid, ':person' => $personId, ':at' => $at, ':mid' => $msgId],
            )->execute();

            $this->db->createCommand(
                'UPDATE notifications.items SET read_at = COALESCE(read_at, now())
                 WHERE organization_id = CAST(:org AS uuid) AND person_id = CAST(:person AS uuid)
                   AND room_id = CAST(:room AS uuid) AND read_at IS NULL',
                [':org' => $orgId, ':person' => $personId, ':room' => $rid],
            )->execute();

            $publishedEvents[] = $this->realtime->publish(
                organizationId: $orgId,
                channel: 'room:' . $rid,
                eventType: 'chat.read',
                payload: ['roomId' => $rid, 'personId' => $personId, 'lastReadMessageId' => $msgId, 'lastReadAt' => $at],
                actorAccountId: $identity->accountId,
                actorPersonId: $personId,
                aggregateType: 'chat_room',
                aggregateId: $rid,
            );
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return ['read' => true, 'lastReadMessageId' => $msgId];
    }

    /** @return array{pinnedMessageId: ?string} */
    public function pinMessage(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomId,
        ?string $messageId,
    ): array {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $this->assertRoomMember($orgId, $rid, $personId);

        $mid = $messageId !== null && $messageId !== '' ? RealtimeService::assertUuid($messageId, 'messageId') : null;
        if ($mid !== null) {
            $exists = $this->db->createCommand(
                'SELECT 1 FROM chat.messages WHERE id = CAST(:id AS uuid) AND room_id = CAST(:room AS uuid) AND deleted_at IS NULL',
                [':id' => $mid, ':room' => $rid],
            )->queryScalar();
            if ($exists === false) {
                throw new HttpError(404, 'message_not_found');
            }
        }

        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (&$publishedEvents, $identity, $orgId, $rid, $personId, $mid): void {
            $this->db->createCommand(
                'UPDATE chat.rooms SET pinned_message_id = CAST(:mid AS uuid), updated_at = now()
                 WHERE id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)',
                [':mid' => $mid, ':room' => $rid, ':org' => $orgId],
            )->execute();

            if ($mid !== null) {
                $this->db->createCommand(
                    'UPDATE chat.messages SET pinned_at = now(), pinned_by_person_id = CAST(:person AS uuid)
                     WHERE id = CAST(:mid AS uuid)',
                    [':person' => $personId, ':mid' => $mid],
                )->execute();
            }

            $publishedEvents[] = $this->realtime->publish(
                organizationId: $orgId,
                channel: 'room:' . $rid,
                eventType: 'chat.room.pinned',
                payload: ['roomId' => $rid, 'pinnedMessageId' => $mid],
                actorAccountId: $identity->accountId,
                actorPersonId: $personId,
                aggregateType: 'chat_room',
                aggregateId: $rid,
            );
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return ['pinnedMessageId' => $mid];
    }

    /** @param array<string, mixed> $input */
    public function saveDraft(AuthenticatedIdentity $identity, string $organizationId, string $roomId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $this->assertRoomMember($orgId, $rid, $personId);

        $text = isset($input['draftText']) && is_string($input['draftText']) ? mb_substr($input['draftText'], 0, 10000) : null;
        if ($text === '') {
            $text = null;
        }
        $replyToId = isset($input['replyToId']) && is_string($input['replyToId']) && $input['replyToId'] !== ''
            ? RealtimeService::assertUuid($input['replyToId'], 'replyToId') : null;

        $this->db->createCommand(
            'UPDATE chat.members SET draft_text = :text, draft_reply_to_id = CAST(:reply AS uuid), draft_updated_at = now()
             WHERE room_id = CAST(:room AS uuid) AND person_id = CAST(:person AS uuid)',
            [':text' => $text, ':reply' => $replyToId, ':room' => $rid, ':person' => $personId],
        )->execute();

        $this->realtime->publish(
            organizationId: $orgId,
            channel: 'user:' . $personId,
            eventType: 'chat.draft.updated',
            payload: ['roomId' => $rid, 'draftText' => $text, 'replyToId' => $replyToId],
            actorAccountId: $identity->accountId,
            actorPersonId: $personId,
            targetPersonIds: [$personId],
            aggregateType: 'chat_room',
            aggregateId: $rid,
        );

        return ['saved' => true, 'draftText' => $text, 'replyToId' => $replyToId];
    }

    /**
     * Adds a member to a room, enforcing domain contact policy and moderator permissions.
     *
     * @return array{added: true, roomId: string, personId: string}
     */
    public function addMember(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomId,
        string $targetPersonId,
    ): array {
        $actorPersonId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $targetPid = RealtimeService::assertUuid($targetPersonId, 'targetPersonId');

        $member = $this->assertRoomMember($orgId, $rid, $actorPersonId);
        $isModerator = in_array($member['role'], ['owner', 'admin', 'moderator'], true)
            || $this->permissions->hasPermission($identity->accountId, 'chat.manage');
        if (!$isModerator) {
            throw new HttpError(403, 'cannot_add_member');
        }

        $room = $this->db->createCommand(
            'SELECT room_type AS "roomType", title, context_type AS "contextType", context_id::text AS "contextId"
             FROM chat.rooms WHERE id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':room' => $rid, ':org' => $orgId],
        )->queryOne();
        if (!is_array($room)) {
            throw new HttpError(404, 'room_not_found');
        }

        $roomType = (string) ($room['roomType'] ?? 'group');
        if ($roomType === 'direct') {
            throw new HttpError(422, 'cannot_add_to_direct_room');
        }
        $ctxType = is_string($room['contextType'] ?? null) ? $room['contextType'] : null;
        $ctxId = is_string($room['contextId'] ?? null) ? $room['contextId'] : null;
        if (!$this->contextPolicy->canCreateRoomWith($identity, $orgId, $roomType, [$targetPid], $ctxType, $ctxId)) {
            throw new HttpError(403, 'chat_context_denied');
        }

        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (&$publishedEvents, $identity, $orgId, $rid, $actorPersonId, $targetPid, $room, $roomType, $ctxType, $ctxId): void {
            $this->db->createCommand(
                "INSERT INTO chat.members (room_id, organization_id, person_id, role, can_write, left_at)
                 VALUES (CAST(:room AS uuid), CAST(:org AS uuid), CAST(:target AS uuid), 'member', true, NULL)
                 ON CONFLICT (room_id, person_id) DO UPDATE SET left_at = NULL",
                [':room' => $rid, ':org' => $orgId, ':target' => $targetPid],
            )->execute();

            $publishedEvents = $this->realtime->publishBatch([
                [
                    'organizationId' => $orgId,
                    'channel' => 'room:' . $rid,
                    'eventType' => 'chat.member.added',
                    'payload' => ['roomId' => $rid, 'personId' => $targetPid],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $actorPersonId,
                    'targetPersonIds' => null,
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $rid,
                ],
                [
                    'organizationId' => $orgId,
                    'channel' => 'user:' . $targetPid,
                    'eventType' => 'chat.room.created',
                    'payload' => [
                        'roomId' => $rid,
                        'roomType' => $roomType,
                        'title' => (string) ($room['title'] ?? ''),
                        'contextType' => $ctxType,
                        'contextId' => $ctxId,
                    ],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $actorPersonId,
                    'targetPersonIds' => [$targetPid],
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $rid,
                ],
            ]);
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return ['added' => true, 'roomId' => $rid, 'personId' => $targetPid];
    }

    /**
     * Removes a member from a chat room, immediately revoking access (`left_at = now()`)
     * and emitting `chat.member.removed` so WebSocket servers drop `room:{id}` subscriptions.
     *
     * @return array{removed: true, roomId: string, personId: string}
     */
    public function removeMember(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomId,
        string $targetPersonId,
    ): array {
        $actorPersonId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $targetPid = RealtimeService::assertUuid($targetPersonId, 'targetPersonId');

        if ($targetPid === $actorPersonId) {
            $this->leaveRoom($identity, $orgId, $rid);
            return ['removed' => true, 'roomId' => $rid, 'personId' => $targetPid];
        }

        $member = $this->assertRoomMember($orgId, $rid, $actorPersonId);
        $isModerator = in_array($member['role'], ['owner', 'admin', 'moderator'], true)
            || $this->permissions->hasPermission($identity->accountId, 'chat.manage');
        if (!$isModerator) {
            throw new HttpError(403, 'cannot_remove_member');
        }

        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (&$publishedEvents, $identity, $orgId, $rid, $actorPersonId, $targetPid): void {
            $revoked = $this->db->createCommand(
                'UPDATE chat.members SET left_at = now()
                 WHERE room_id = CAST(:room AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND person_id = CAST(:target AS uuid)
                   AND left_at IS NULL
                 RETURNING person_id::text',
                [':room' => $rid, ':org' => $orgId, ':target' => $targetPid],
            )->queryScalar();

            if (!is_string($revoked)) {
                throw new HttpError(404, 'member_not_found');
            }

            $publishedEvents = $this->realtime->publishBatch([
                [
                    'organizationId' => $orgId,
                    'channel' => 'room:' . $rid,
                    'eventType' => 'chat.member.removed',
                    'payload' => ['roomId' => $rid, 'personId' => $targetPid],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $actorPersonId,
                    'targetPersonIds' => null,
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $rid,
                ],
                [
                    'organizationId' => $orgId,
                    'channel' => 'user:' . $targetPid,
                    'eventType' => 'chat.member.removed',
                    'payload' => ['roomId' => $rid, 'personId' => $targetPid],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $actorPersonId,
                    'targetPersonIds' => [$targetPid],
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $rid,
                ],
            ]);
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return ['removed' => true, 'roomId' => $rid, 'personId' => $targetPid];
    }

    /**
     * Allows the authenticated member to leave a room immediately, revoking their access.
     *
     * @return array{left: true, roomId: string, personId: string}
     */
    public function leaveRoom(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $roomId,
    ): array {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $this->assertRoomMember($orgId, $rid, $personId);

        /** @var list<RealtimeEvent> $publishedEvents */
        $publishedEvents = [];
        $this->db->transaction(function () use (&$publishedEvents, $identity, $orgId, $rid, $personId): void {
            $this->db->createCommand(
                'UPDATE chat.members SET left_at = now()
                 WHERE room_id = CAST(:room AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND person_id = CAST(:person AS uuid)
                   AND left_at IS NULL',
                [':room' => $rid, ':org' => $orgId, ':person' => $personId],
            )->execute();

            $publishedEvents = $this->realtime->publishBatch([
                [
                    'organizationId' => $orgId,
                    'channel' => 'room:' . $rid,
                    'eventType' => 'chat.member.removed',
                    'payload' => ['roomId' => $rid, 'personId' => $personId],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $personId,
                    'targetPersonIds' => null,
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $rid,
                ],
                [
                    'organizationId' => $orgId,
                    'channel' => 'user:' . $personId,
                    'eventType' => 'chat.member.removed',
                    'payload' => ['roomId' => $rid, 'personId' => $personId],
                    'actorAccountId' => $identity->accountId,
                    'actorPersonId' => $personId,
                    'targetPersonIds' => [$personId],
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $rid,
                ],
            ]);
        });

        $this->realtime->tryPushEvents($publishedEvents);
        return ['left' => true, 'roomId' => $rid, 'personId' => $personId];
    }

    /** @param array<string, mixed> $input */
    public function updateRoomPreferences(AuthenticatedIdentity $identity, string $organizationId, string $roomId, array $input): array
    {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $rid = RealtimeService::assertUuid($roomId, 'roomId');
        $this->assertRoomMember($orgId, $rid, $personId);

        if (array_key_exists('archived', $input)) {
            $this->db->createCommand(
                'UPDATE chat.members SET archived_at = CASE WHEN :arch THEN now() ELSE NULL END
                 WHERE room_id = CAST(:room AS uuid) AND person_id = CAST(:person AS uuid)',
                [':arch' => (bool) $input['archived'], ':room' => $rid, ':person' => $personId],
            )->execute();
        }
        if (array_key_exists('mutedMinutes', $input)) {
            $mins = (int) $input['mutedMinutes'];
            $mutedUntil = $mins > 0 ? gmdate(DATE_ATOM, time() + ($mins * 60)) : null;
            $this->db->createCommand(
                'UPDATE chat.members SET muted_until = CAST(:until AS timestamptz)
                 WHERE room_id = CAST(:room AS uuid) AND person_id = CAST(:person AS uuid)',
                [':until' => $mutedUntil, ':room' => $rid, ':person' => $personId],
            )->execute();
        }
        if (array_key_exists('pinnedOrder', $input)) {
            $order = $input['pinnedOrder'] === null ? null : (int) $input['pinnedOrder'];
            $this->db->createCommand(
                'UPDATE chat.members SET pinned_order = :ord
                 WHERE room_id = CAST(:room AS uuid) AND person_id = CAST(:person AS uuid)',
                [':ord' => $order, ':room' => $rid, ':person' => $personId],
            )->execute();
        }

        return $this->getRoom($identity, $orgId, $rid);
    }

    /** @return array<string, mixed> */
    public function searchMessages(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $queryText,
        ?string $roomId = null,
    ): array {
        $personId = $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        $q = trim($queryText);
        if ($q === '') {
            return ['items' => []];
        }

        $params = [':org' => $orgId, ':person' => $personId, ':q' => '%' . $q . '%'];
        $roomFilter = '';
        if ($roomId !== null && $roomId !== '') {
            $roomFilter = ' AND m.room_id = CAST(:room AS uuid)';
            $params[':room'] = RealtimeService::assertUuid($roomId, 'roomId');
        }

        $rows = $this->db->createCommand(
            "SELECT
                m.id::text, m.room_id::text AS \"roomId\", r.title AS \"roomTitle\",
                m.sender_person_id::text AS \"senderPersonId\",
                COALESCE(NULLIF(p.display_name, ''), CONCAT_WS(' ', p.given_name, p.family_name)) AS \"senderName\",
                m.body, m.kind, m.created_at::text AS \"createdAt\"
             FROM chat.messages m
             JOIN chat.rooms r ON r.id = m.room_id AND r.organization_id = m.organization_id
             JOIN chat.members mem ON mem.room_id = r.id AND mem.person_id = CAST(:person AS uuid) AND mem.left_at IS NULL
             LEFT JOIN core.persons p ON p.id = m.sender_person_id
             WHERE m.organization_id = CAST(:org AS uuid)
               AND m.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM chat.message_deletions md WHERE md.message_id = m.id AND md.person_id = CAST(:person AS uuid))
               AND m.body ILIKE :q
               {$roomFilter}
             ORDER BY m.created_at DESC
             LIMIT 50",
            $params,
        )->queryAll();

        return ['items' => $rows];
    }

    /** @return array{items: list<array{id: string, name: string}>, personId: string} */
    public function myOrganizations(AuthenticatedIdentity $identity): array
    {
        $personId = $identity->personId;
        if ($personId === null || $personId === '') {
            throw new HttpError(403, 'access_denied');
        }
        $rows = $this->db->createCommand(
            "SELECT o.id::text, o.name
             FROM core.organization_memberships om
             JOIN core.organizations o ON o.id = om.organization_id
             WHERE om.person_id = CAST(:person AS uuid)
               AND om.status = 'active'
               AND o.status = 'active'
             ORDER BY o.name ASC",
            [':person' => $personId],
        )->queryAll();

        $items = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $items[] = [
                'id' => (string) $r['id'],
                'name' => (string) $r['name'],
            ];
        }
        return ['items' => $items, 'personId' => $personId];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function contacts(AuthenticatedIdentity $identity, string $organizationId, ?string $query = null): array
    {
        $this->assertAccess($identity, $organizationId, 'chat.use');
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        return ['items' => $this->contextPolicy->listAvailableContacts($identity, $orgId, $query)];
    }

    private function assertAccess(AuthenticatedIdentity $identity, string $organizationId, string $permission): string
    {
        $personId = $identity->personId;
        if ($personId === null || $personId === '') {
            throw new HttpError(403, 'access_denied');
        }
        $orgId = RealtimeService::assertUuid($organizationId, 'organizationId');
        if (!$this->organizations->activeMember($personId, $orgId)) {
            throw new HttpError(403, 'organization_access_denied');
        }
        if (!$this->permissions->hasPermission($identity->accountId, $permission)) {
            throw new HttpError(403, 'access_denied');
        }
        return $personId;
    }

    /** @return array{role: string, canWrite: bool} */
    private function assertRoomMember(string $organizationId, string $roomId, string $personId): array
    {
        $row = $this->db->createCommand(
            'SELECT role, can_write AS "canWrite" FROM chat.members
             WHERE room_id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid) AND left_at IS NULL',
            [':room' => $roomId, ':org' => $organizationId, ':person' => $personId],
        )->queryOne();
        if (!is_array($row)) {
            throw new HttpError(404, 'room_not_found');
        }
        return ['role' => (string) $row['role'], 'canWrite' => (bool) $row['canWrite']];
    }

    /** @return array<string, mixed> */
    private function fetchSingleMessage(string $orgId, string $roomId, string $personId, string $messageId): array
    {
        $row = $this->db->createCommand(
            'SELECT
                m.id::text, m.room_id::text AS "roomId", m.seq,
                m.sender_person_id::text AS "senderPersonId",
                COALESCE(NULLIF(sp.display_name, \'\'), CONCAT_WS(\' \', sp.given_name, sp.family_name), \'User\') AS "senderName",
                CASE WHEN m.deleted_at IS NOT NULL THEN NULL ELSE m.body END AS body,
                m.kind, m.media_asset_id::text AS "mediaAssetId",
                m.reply_to_id::text AS "replyToId",
                m.forwarded_from_message_id::text AS "forwardedFromMessageId",
                m.forwarded_from_room_id::text AS "forwardedFromRoomId",
                m.metadata::text AS "metadataJson",
                m.edited_at::text AS "editedAt", m.deleted_at::text AS "deletedAt",
                m.deleted_for_all AS "deletedForAll", m.pinned_at::text AS "pinnedAt",
                m.created_at::text AS "createdAt",
                ma.kind AS "mediaKind", ma.original_name AS "mediaName",
                COALESCE(ma.verified_mime, ma.original_mime) AS "mediaMime",
                ma.size_bytes AS "mediaSize", ma.duration_seconds AS "mediaDuration", ma.status AS "mediaStatus",
                rm.body AS "replyBody", rm.sender_person_id::text AS "replySenderPersonId",
                false AS "isReadByPeer"
             FROM chat.messages m
             LEFT JOIN core.persons sp ON sp.id = m.sender_person_id
             LEFT JOIN media.assets ma ON ma.id = m.media_asset_id
             LEFT JOIN chat.messages rm ON rm.id = m.reply_to_id
             WHERE m.id = CAST(:id AS uuid) AND m.room_id = CAST(:room AS uuid) AND m.organization_id = CAST(:org AS uuid)',
            [':id' => $messageId, ':room' => $roomId, ':org' => $orgId],
        )->queryOne();

        if (!is_array($row)) {
            throw new HttpError(404, 'message_not_found');
        }
        return $this->formatMessageRow($row, []);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{emoji: string, count: int, reacted: bool}> $reactions
     * @return array<string, mixed>
     */
    private function formatMessageRow(array $row, array $reactions): array
    {
        $row['reactions'] = $reactions;
        $mediaAssetId = $row['mediaAssetId'] ?? null;
        if ($mediaAssetId !== null && ($row['deletedAt'] ?? null) === null) {
            $row['media'] = [
                'id' => $mediaAssetId,
                'kind' => $row['mediaKind'] ?? ($row['kind'] ?? ''),
                'name' => $row['mediaName'] ?? '',
                'mime' => $row['mediaMime'] ?? '',
                'size' => (int) ($row['mediaSize'] ?? 0),
                'duration' => isset($row['mediaDuration']) && $row['mediaDuration'] !== null ? (float) $row['mediaDuration'] : null,
                'status' => $row['mediaStatus'] ?? 'READY',
            ];
        } else {
            $row['media'] = null;
        }
        $replyToId = $row['replyToId'] ?? null;
        if ($replyToId !== null) {
            $row['replyTo'] = [
                'id' => $replyToId,
                'body' => $row['replyBody'] ?? null,
                'senderPersonId' => $row['replySenderPersonId'] ?? null,
            ];
        } else {
            $row['replyTo'] = null;
        }
        $meta = json_decode((string) ($row['metadataJson'] ?? '{}'), true);
        $row['metadata'] = is_array($meta) ? $meta : [];
        unset(
            $row['metadataJson'],
            $row['mediaKind'],
            $row['mediaName'],
            $row['mediaMime'],
            $row['mediaSize'],
            $row['mediaDuration'],
            $row['mediaStatus'],
            $row['replyBody'],
            $row['replySenderPersonId'],
        );
        return $row;
    }
}
