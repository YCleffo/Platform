<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Realtime\Application\RealtimeService;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;

/**
 * Synchronizes domain membership and revokes room access on canonical Universal Chat (`chat.members`)
 * when Education domain relationships (e.g. guardian links, group enrollments) change.
 * Legacy two-way/one-way mirroring from `education.threads`/`education.messages` has been removed.
 */
final readonly class EducationChatBridge
{
    public function __construct(
        private ConnectionInterface $db,
        private ?RealtimeService $realtime = null,
    ) {}

    /**
     * Synchronizes room membership in `chat.members` against the authoritative list of active person IDs,
     * setting `left_at = now()` on removed members and emitting `chat.member.removed`.
     *
     * @param list<string> $activePersonIds
     * @return list<string> Removed person IDs
     */
    public function syncRoomMembership(
        string $organizationId,
        string $roomId,
        array $activePersonIds,
        ?string $actorAccountId = null,
        ?string $actorPersonId = null,
    ): array {
        $activeClean = array_values(array_unique(array_filter(
            array_map('strtolower', $activePersonIds),
            static fn(string $p): bool => $p !== '',
        )));

        foreach ($activeClean as $pid) {
            $this->db->createCommand(
                "INSERT INTO chat.members (room_id, organization_id, person_id, role, can_write, left_at)
                 VALUES (CAST(:room AS uuid), CAST(:org AS uuid), CAST(:person AS uuid), 'member', true, NULL)
                 ON CONFLICT (room_id, person_id) DO UPDATE SET left_at = NULL",
                [':room' => $roomId, ':org' => $organizationId, ':person' => $pid],
            )->execute();
        }

        if ($activeClean === []) {
            $removedRows = $this->db->createCommand(
                'UPDATE chat.members SET left_at = now()
                 WHERE room_id = CAST(:room AS uuid) AND organization_id = CAST(:org AS uuid) AND left_at IS NULL
                 RETURNING person_id::text',
                [':room' => $roomId, ':org' => $organizationId],
            )->queryColumn();
        } else {
            $pgArray = '{' . implode(',', $activeClean) . '}';
            $removedRows = $this->db->createCommand(
                'UPDATE chat.members SET left_at = now()
                 WHERE room_id = CAST(:room AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND left_at IS NULL
                   AND NOT (person_id = ANY(CAST(:active AS uuid[])))
                 RETURNING person_id::text',
                [':room' => $roomId, ':org' => $organizationId, ':active' => $pgArray],
            )->queryColumn();
        }

        $removed = [];
        foreach ($removedRows as $pid) {
            if (is_string($pid) && $pid !== '') {
                $removed[] = $pid;
            }
        }

        if ($removed !== [] && $this->realtime !== null) {
            $inTx = $this->db instanceof PdoConnectionInterface && $this->db->getActivePdo()->inTransaction();
            $specs = [];
            foreach ($removed as $pid) {
                $specs[] = [
                    'organizationId' => $organizationId,
                    'channel' => 'room:' . $roomId,
                    'eventType' => 'chat.member.removed',
                    'payload' => ['roomId' => $roomId, 'personId' => $pid],
                    'actorAccountId' => $actorAccountId,
                    'actorPersonId' => $actorPersonId,
                    'targetPersonIds' => null,
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $roomId,
                ];
                $specs[] = [
                    'organizationId' => $organizationId,
                    'channel' => 'user:' . $pid,
                    'eventType' => 'chat.member.removed',
                    'payload' => ['roomId' => $roomId, 'personId' => $pid],
                    'actorAccountId' => $actorAccountId,
                    'actorPersonId' => $actorPersonId,
                    'targetPersonIds' => [$pid],
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $roomId,
                ];
            }
            $events = $this->realtime->publishBatch($specs);
            if (!$inTx) {
                $this->realtime->tryPushEvents($events);
            }
        }

        return $removed;
    }

    /**
     * Immediately revokes a member's access to a chat room and emits `chat.member.removed`
     * so connected WebSocket sessions drop the `room:{id}` subscription.
     */
    public function revokeMemberAccess(
        string $organizationId,
        string $roomId,
        string $personId,
        ?string $actorAccountId = null,
        ?string $actorPersonId = null,
    ): bool {
        $revoked = $this->db->createCommand(
            'UPDATE chat.members SET left_at = now()
             WHERE room_id = CAST(:room AS uuid)
               AND organization_id = CAST(:org AS uuid)
               AND person_id = CAST(:person AS uuid)
               AND left_at IS NULL
             RETURNING person_id::text',
            [':room' => $roomId, ':org' => $organizationId, ':person' => $personId],
        )->queryScalar();

        if (!is_string($revoked)) {
            return false;
        }

        if ($this->realtime !== null) {
            $inTx = $this->db instanceof PdoConnectionInterface && $this->db->getActivePdo()->inTransaction();
            $events = $this->realtime->publishBatch([
                [
                    'organizationId' => $organizationId,
                    'channel' => 'room:' . $roomId,
                    'eventType' => 'chat.member.removed',
                    'payload' => ['roomId' => $roomId, 'personId' => $personId],
                    'actorAccountId' => $actorAccountId,
                    'actorPersonId' => $actorPersonId,
                    'targetPersonIds' => null,
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $roomId,
                ],
                [
                    'organizationId' => $organizationId,
                    'channel' => 'user:' . $personId,
                    'eventType' => 'chat.member.removed',
                    'payload' => ['roomId' => $roomId, 'personId' => $personId],
                    'actorAccountId' => $actorAccountId,
                    'actorPersonId' => $actorPersonId,
                    'targetPersonIds' => [$personId],
                    'aggregateType' => 'chat_room',
                    'aggregateId' => $roomId,
                ],
            ]);
            if (!$inTx) {
                $this->realtime->tryPushEvents($events);
            }
        }

        return true;
    }
}
