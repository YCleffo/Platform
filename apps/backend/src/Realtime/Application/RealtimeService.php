<?php

declare(strict_types=1);

namespace App\Realtime\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use Predis\ClientInterface;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Driver\Pdo\PdoConnectionInterface;

/**
 * Transactional Outbox + Redis Pub/Sub + Presence + Typing + Signed WS Ticket service.
 *
 * Delivery guarantee: at-least-once with durable PostgreSQL outbox, lease-based locking (`locked_until`),
 * exponential retry backoff (`next_retry_at`), dead-lettering (`dead_lettered_at`),
 * and client/server `eventId`/`seq` deduplication.
 * Out-of-order transaction commits are preserved because `relayPending()` scans `dispatched_at IS NULL`
 * ordered by `seq ASC` rather than advancing past a monotonic watermark.
 */
final readonly class RealtimeService
{
    public const REDIS_CHANNEL = 'platform:realtime:events';

    public function __construct(
        private ConnectionInterface $db,
        private ClientInterface $redis,
        private RealtimeChannelAuthorizerInterface $authorizer,
        private string $ticketSecret = '',
    ) {}

    public static function assertUuid(string $value, string $field = 'id'): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value) !== 1) {
            throw new HttpError(422, 'invalid_uuid', "Invalid {$field}");
        }
        return strtolower($value);
    }

    /**
     * Enqueues a durable realtime event in PostgreSQL outbox and publishes via Redis immediately when outside TX.
     *
     * @param array<string, mixed> $payload
     * @param list<string>|null $targetPersonIds
     */
    public function publish(
        string $organizationId,
        string $channel,
        string $eventType,
        array $payload,
        ?string $actorAccountId = null,
        ?string $actorPersonId = null,
        ?array $targetPersonIds = null,
        string $aggregateType = '',
        string $aggregateId = '',
    ): RealtimeEvent {
        $inTransaction = $this->db instanceof PdoConnectionInterface
            && $this->db->getActivePdo()->inTransaction();

        $row = $this->db->createCommand(
            <<<'SQL'
            INSERT INTO realtime.outbox_events (
                organization_id, channel, event_type, aggregate_type, aggregate_id,
                actor_account_id, actor_person_id, payload, target_person_ids
            ) VALUES (
                CAST(:org AS uuid), :channel, :eventType, :aggType, :aggId,
                CAST(:actorAccount AS uuid), CAST(:actorPerson AS uuid), CAST(:payload AS jsonb), CAST(:targets AS jsonb)
            )
            RETURNING
                seq, event_id::text AS "eventId", organization_id::text AS "organizationId",
                channel, event_type AS "eventType", aggregate_type AS "aggregateType", aggregate_id AS "aggregateId",
                actor_account_id::text AS "actorAccountId", actor_person_id::text AS "actorPersonId",
                payload::text AS "payloadJson", target_person_ids::text AS "targetsJson",
                created_at::text AS "createdAt"
            SQL,
            [
                ':org' => $organizationId,
                ':channel' => $channel,
                ':eventType' => $eventType,
                ':aggType' => $aggregateType,
                ':aggId' => $aggregateId,
                ':actorAccount' => $actorAccountId,
                ':actorPerson' => $actorPersonId,
                ':payload' => $payload === [] ? '{}' : json_encode($payload, JSON_THROW_ON_ERROR),
                ':targets' => $targetPersonIds === null ? null : json_encode($targetPersonIds, JSON_THROW_ON_ERROR),
            ],
        )->queryOne();

        if (!is_array($row)) {
            throw new \RuntimeException('Failed to insert realtime outbox event.');
        }

        $event = self::hydrateRow($row);
        if (!$inTransaction) {
            $this->tryPushEvents([$event]);
        }

        return $event;
    }

    /**
     * Batch-enqueues durable realtime events in PostgreSQL outbox in a single statement.
     *
     * @param list<array{
     *     organizationId: string,
     *     channel: string,
     *     eventType: string,
     *     payload: array<string, mixed>,
     *     actorAccountId?: ?string,
     *     actorPersonId?: ?string,
     *     targetPersonIds?: ?list<string>,
     *     aggregateType?: string,
     *     aggregateId?: string,
     * }> $specs
     * @return list<RealtimeEvent>
     */
    public function publishBatch(array $specs): array
    {
        if ($specs === []) {
            return [];
        }
        if (count($specs) === 1) {
            $s = $specs[0];
            return [$this->publish(
                organizationId: $s['organizationId'],
                channel: $s['channel'],
                eventType: $s['eventType'],
                payload: $s['payload'],
                actorAccountId: $s['actorAccountId'] ?? null,
                actorPersonId: $s['actorPersonId'] ?? null,
                targetPersonIds: $s['targetPersonIds'] ?? null,
                aggregateType: $s['aggregateType'] ?? '',
                aggregateId: $s['aggregateId'] ?? '',
            )];
        }

        $inTransaction = $this->db instanceof PdoConnectionInterface
            && $this->db->getActivePdo()->inTransaction();

        $valuesSql = [];
        $params = [];
        foreach ($specs as $i => $s) {
            $valuesSql[] = sprintf(
                '(CAST(:org%d AS uuid), :chan%d, :type%d, :aggType%d, :aggId%d, CAST(:acc%d AS uuid), CAST(:per%d AS uuid), CAST(:pay%d AS jsonb), CAST(:tgt%d AS jsonb))',
                $i,
                $i,
                $i,
                $i,
                $i,
                $i,
                $i,
                $i,
                $i,
            );
            $params[':org' . $i] = $s['organizationId'];
            $params[':chan' . $i] = $s['channel'];
            $params[':type' . $i] = $s['eventType'];
            $params[':aggType' . $i] = $s['aggregateType'] ?? '';
            $params[':aggId' . $i] = $s['aggregateId'] ?? '';
            $params[':acc' . $i] = $s['actorAccountId'] ?? null;
            $params[':per' . $i] = $s['actorPersonId'] ?? null;
            $params[':pay' . $i] = $s['payload'] === [] ? '{}' : json_encode($s['payload'], JSON_THROW_ON_ERROR);
            $targets = $s['targetPersonIds'] ?? null;
            $params[':tgt' . $i] = $targets === null ? null : json_encode($targets, JSON_THROW_ON_ERROR);
        }

        $sql = 'INSERT INTO realtime.outbox_events (
                organization_id, channel, event_type, aggregate_type, aggregate_id,
                actor_account_id, actor_person_id, payload, target_person_ids
            ) VALUES ' . implode(', ', $valuesSql) . '
            RETURNING
                seq, event_id::text AS "eventId", organization_id::text AS "organizationId",
                channel, event_type AS "eventType", aggregate_type AS "aggregateType", aggregate_id AS "aggregateId",
                actor_account_id::text AS "actorAccountId", actor_person_id::text AS "actorPersonId",
                payload::text AS "payloadJson", target_person_ids::text AS "targetsJson",
                created_at::text AS "createdAt"';

        $rows = $this->db->createCommand($sql, $params)->queryAll();
        $events = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $events[] = self::hydrateRow($r);
        }

        if (!$inTransaction) {
            $this->tryPushEvents($events);
        }

        return $events;
    }

    /**
     * Pushes a specific list of already-committed outbox events directly to Redis Pub/Sub
     * without scanning or locking `realtime.outbox_events`, marking `dispatched_at` ONLY after publish succeeds.
     *
     * @param list<RealtimeEvent> $events
     */
    public function tryPushEvents(array $events): int
    {
        if ($events === []) {
            return 0;
        }
        usort($events, static fn(RealtimeEvent $a, RealtimeEvent $b): int => $a->seq <=> $b->seq);

        $succeededSeqs = [];
        $failed = [];

        if (count($events) > 1 && $this->redis instanceof \Predis\Client) {
            $encodedBySeq = [];
            foreach ($events as $event) {
                $encodedBySeq[$event->seq] = json_encode($event->toArray(), JSON_THROW_ON_ERROR);
            }
            try {
                $pipe = $this->redis->pipeline();
                if ($pipe instanceof \Predis\Pipeline\Pipeline) {
                    foreach ($encodedBySeq as $json) {
                        $pipe->publish(self::REDIS_CHANNEL, $json);
                    }
                    $pipe->execute();
                } else {
                    foreach ($encodedBySeq as $json) {
                        $this->redis->publish(self::REDIS_CHANNEL, $json);
                    }
                }
                $succeededSeqs = array_keys($encodedBySeq);
            } catch (Throwable $e) {
                $err = mb_substr($e->getMessage(), 0, 500);
                foreach (array_keys($encodedBySeq) as $seq) {
                    $failed[] = ['seq' => $seq, 'error' => $err];
                }
            }
        } else {
            foreach ($events as $event) {
                try {
                    $this->redis->publish(self::REDIS_CHANNEL, json_encode($event->toArray(), JSON_THROW_ON_ERROR));
                    $succeededSeqs[] = $event->seq;
                } catch (Throwable $e) {
                    $failed[] = ['seq' => $event->seq, 'error' => mb_substr($e->getMessage(), 0, 500)];
                }
            }
        }

        if ($succeededSeqs !== []) {
            $pgArray = '{' . implode(',', array_map('intval', $succeededSeqs)) . '}';
            $this->db->createCommand(
                'UPDATE realtime.outbox_events
                 SET dispatched_at = COALESCE(dispatched_at, now()),
                     locked_until = NULL,
                     attempts = attempts + 1,
                     last_error = NULL
                 WHERE seq = ANY(CAST(:seqs AS bigint[]))',
                [':seqs' => $pgArray],
            )->execute();
        }

        foreach ($failed as $fail) {
            $this->db->createCommand(
                "UPDATE realtime.outbox_events
                 SET locked_until = NULL,
                     attempts = attempts + 1,
                     next_retry_at = now() + (LEAST(300, POWER(2, LEAST(attempts + 1, 8))) * interval '1 second'),
                     dead_lettered_at = CASE WHEN attempts + 1 >= max_attempts THEN now() ELSE NULL END,
                     last_error = :err
                 WHERE seq = :seq",
                [':err' => $fail['error'], ':seq' => $fail['seq']],
            )->execute();
        }

        return count($succeededSeqs);
    }

    /**
     * Claims un-dispatched durable events using lease locking (`locked_until`), publishes to Redis,
     * and sets `dispatched_at` ONLY after Redis publish succeeds. Failed events receive exponential backoff
     * and are dead-lettered when `attempts >= max_attempts`.
     */
    public function relayPending(int $limit = 100): int
    {
        /** @var list<array{event: RealtimeEvent, attempts: int}> $claimed */
        $claimed = [];
        $this->db->transaction(function () use ($limit, &$claimed): void {
            $rows = $this->db->createCommand(
                <<<'SQL'
                WITH claimed AS (
                    SELECT seq
                    FROM realtime.outbox_events
                    WHERE dispatched_at IS NULL
                      AND dead_lettered_at IS NULL
                      AND (locked_until IS NULL OR locked_until < now())
                      AND (next_retry_at IS NULL OR next_retry_at <= now())
                    ORDER BY seq ASC
                    LIMIT :limit
                    FOR UPDATE SKIP LOCKED
                )
                UPDATE realtime.outbox_events o
                SET locked_until = now() + interval '15 seconds',
                    attempts = o.attempts + 1
                FROM claimed c
                WHERE o.seq = c.seq
                RETURNING
                    o.seq, o.event_id::text AS "eventId", o.organization_id::text AS "organizationId",
                    o.channel, o.event_type AS "eventType", o.aggregate_type AS "aggregateType", o.aggregate_id AS "aggregateId",
                    o.actor_account_id::text AS "actorAccountId", o.actor_person_id::text AS "actorPersonId",
                    o.payload::text AS "payloadJson", o.target_person_ids::text AS "targetsJson",
                    o.created_at::text AS "createdAt", o.attempts
                SQL,
                [':limit' => max(1, min(500, $limit))],
            )->queryAll();

            /** @var array<string, mixed> $r */
            foreach ($rows as $r) {
                $claimed[] = [
                    'event' => self::hydrateRow($r),
                    'attempts' => (int) ($r['attempts'] ?? 1),
                ];
            }
        });

        if ($claimed === []) {
            return 0;
        }

        usort(
            $claimed,
            /**
             * @param array{event: RealtimeEvent, attempts: int} $a
             * @param array{event: RealtimeEvent, attempts: int} $b
             */
            static fn(array $a, array $b): int => $a['event']->seq <=> $b['event']->seq,
        );

        $succeededSeqs = [];
        $failed = [];
        foreach ($claimed as $entry) {
            $event = $entry['event'];
            try {
                $this->redis->publish(self::REDIS_CHANNEL, json_encode($event->toArray(), JSON_THROW_ON_ERROR));
                $succeededSeqs[] = $event->seq;
            } catch (Throwable $e) {
                $failed[] = [
                    'seq' => $event->seq,
                    'attempts' => $entry['attempts'],
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ];
            }
        }

        if ($succeededSeqs !== []) {
            $pgArray = '{' . implode(',', array_map('intval', $succeededSeqs)) . '}';
            $this->db->createCommand(
                'UPDATE realtime.outbox_events
                 SET dispatched_at = now(),
                     locked_until = NULL,
                     last_error = NULL
                 WHERE seq = ANY(CAST(:seqs AS bigint[]))',
                [':seqs' => $pgArray],
            )->execute();
        }

        foreach ($failed as $fail) {
            $backoffSeconds = min(300, 1 << min(max(1, $fail['attempts']), 8));
            $this->db->createCommand(
                "UPDATE realtime.outbox_events
                 SET locked_until = NULL,
                     next_retry_at = now() + (:backoff * interval '1 second'),
                     dead_lettered_at = CASE WHEN attempts >= max_attempts THEN now() ELSE NULL END,
                     last_error = :err
                 WHERE seq = :seq",
                [
                    ':backoff' => $backoffSeconds,
                    ':err' => $fail['error'],
                    ':seq' => $fail['seq'],
                ],
            )->execute();
        }

        return count($succeededSeqs);
    }

    /**
     * Replays missed events strictly ordered by `seq > sinceSeq`.
     *
     * @return list<array<string, mixed>>
     */
    public function sinceSeq(
        string $accountId,
        string $personId,
        string $organizationId,
        string $channel,
        int $sinceSeq,
        int $limit = 200,
    ): array {
        if (!$this->authorizer->canSubscribe($accountId, $personId, $organizationId, $channel)) {
            throw new HttpError(403, 'channel_forbidden');
        }

        $rows = $this->db->createCommand(
            <<<'SQL'
            SELECT
                seq, event_id::text AS "eventId", organization_id::text AS "organizationId",
                channel, event_type AS "eventType", aggregate_type AS "aggregateType", aggregate_id AS "aggregateId",
                actor_account_id::text AS "actorAccountId", actor_person_id::text AS "actorPersonId",
                payload::text AS "payloadJson", target_person_ids::text AS "targetsJson",
                created_at::text AS "createdAt"
            FROM realtime.outbox_events
            WHERE organization_id = CAST(:org AS uuid)
              AND channel = :channel
              AND seq > :sinceSeq
              AND (target_person_ids IS NULL OR target_person_ids @> CAST(:personJson AS jsonb))
            ORDER BY seq ASC
            LIMIT :limit
            SQL,
            [
                ':org' => $organizationId,
                ':channel' => $channel,
                ':sinceSeq' => max(0, $sinceSeq),
                ':personJson' => json_encode([$personId], JSON_THROW_ON_ERROR),
                ':limit' => max(1, min(500, $limit)),
            ],
        )->queryAll();

        $out = [];
        /** @var array<string, mixed> $r */
        foreach ($rows as $r) {
            $out[] = self::hydrateRow($r)->toArray();
        }
        return $out;
    }

    public function latestSeq(string $organizationId): int
    {
        $val = $this->db->createCommand(
            'SELECT COALESCE(MAX(seq), 0) FROM realtime.outbox_events WHERE organization_id = CAST(:org AS uuid)',
            [':org' => $organizationId],
        )->queryScalar();
        return is_numeric($val) ? (int) $val : 0;
    }

    /** @return array{ticket: string, wsPath: string, expiresAt: string, latestSeq: int, heartbeatMs: int, accountId: string, sessionId: string} */
    public function issueWsTicket(AuthenticatedIdentity $identity, string $organizationId): array
    {
        $personId = $identity->personId;
        if ($personId === null || $personId === '') {
            throw new HttpError(403, 'access_denied');
        }
        $orgId = self::assertUuid($organizationId, 'organizationId');

        if (!$this->authorizer->canSubscribe($identity->accountId, $personId, $orgId, 'org:' . $orgId)) {
            throw new HttpError(403, 'organization_access_denied');
        }

        $ttl = 120;
        $exp = time() + $ttl;
        $payload = json_encode([
            'accountId' => $identity->accountId,
            'personId' => $personId,
            'sessionId' => $identity->sessionId,
            'org' => $orgId,
            'iat' => time(),
            'exp' => $exp,
            'jti' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $encoded, $this->signingKey());
        $ticket = $encoded . '.' . $sig;

        return [
            'ticket' => $ticket,
            'wsPath' => '/ws',
            'expiresAt' => gmdate(DATE_ATOM, $exp),
            'latestSeq' => $this->latestSeq($orgId),
            'heartbeatMs' => 20000,
            'accountId' => $identity->accountId,
            'sessionId' => $identity->sessionId,
        ];
    }

    /** @return array{accountId: string, personId: string, sessionId: string, org: string, exp: int}|null */
    public function verifyWsTicket(string $ticket): ?array
    {
        $parts = explode('.', $ticket, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$encoded, $sig] = $parts;
        $expectedSig = hash_hmac('sha256', $encoded, $this->signingKey());
        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        if (($data['exp'] ?? 0) < time()) {
            return null;
        }
        $accountId = (string) ($data['accountId'] ?? '');
        $personId = (string) ($data['personId'] ?? '');
        $sessionId = (string) ($data['sessionId'] ?? '');
        $orgId = (string) ($data['org'] ?? '');
        if ($accountId === '' || $personId === '' || $orgId === '') {
            return null;
        }

        if (!$this->isConnectionStillAuthorized($accountId, $personId, $sessionId, $orgId)) {
            return null;
        }

        return [
            'accountId' => $accountId,
            'personId' => $personId,
            'sessionId' => $sessionId,
            'org' => $orgId,
            'exp' => (int) $data['exp'],
        ];
    }

    /**
     * Re-verifies live auth session, account status, and org membership for an active WebSocket connection.
     */
    public function isConnectionStillAuthorized(
        string $accountId,
        string $personId,
        string $sessionId,
        string $organizationId,
    ): bool {
        if ($accountId === '' || $personId === '' || $organizationId === '') {
            return false;
        }

        if ($sessionId !== '') {
            $activeSession = $this->db->createCommand(
                "SELECT 1
                 FROM core.auth_sessions s
                 JOIN core.accounts a ON a.id = s.account_id
                 WHERE s.id = CAST(:sid AS uuid)
                   AND s.account_id = CAST(:aid AS uuid)
                   AND s.revoked_at IS NULL
                   AND s.expires_at > now()
                   AND a.status = 'active'",
                [':sid' => $sessionId, ':aid' => $accountId],
            )->queryScalar();
            if ($activeSession === false) {
                return false;
            }
        } else {
            $activeAccount = $this->db->createCommand(
                "SELECT 1 FROM core.accounts WHERE id = CAST(:aid AS uuid) AND status = 'active'",
                [':aid' => $accountId],
            )->queryScalar();
            if ($activeAccount === false) {
                return false;
            }
        }

        return $this->authorizer->canSubscribe($accountId, $personId, $organizationId, 'org:' . $organizationId);
    }

    public function setPresence(string $organizationId, string $personId, bool $online, ?string $clientInstanceId = null): void
    {
        $orgId = self::assertUuid($organizationId, 'organizationId');
        $pid = self::assertUuid($personId, 'personId');
        $key = sprintf('presence:org:%s:person:%s', $orgId, $pid);
        $now = gmdate(DATE_ATOM);

        try {
            if ($online) {
                $this->redis->setex($key, 60, json_encode([
                    'status' => 'online',
                    'lastSeenAt' => $now,
                    'clientInstanceId' => $clientInstanceId,
                ], JSON_THROW_ON_ERROR));
            } else {
                $this->redis->setex($key, 86400 * 7, json_encode([
                    'status' => 'offline',
                    'lastSeenAt' => $now,
                    'clientInstanceId' => $clientInstanceId,
                ], JSON_THROW_ON_ERROR));
            }

            $event = new RealtimeEvent(
                seq: 0,
                eventId: 'ephemeral-presence-' . bin2hex(random_bytes(6)),
                organizationId: $orgId,
                channel: 'org:' . $orgId,
                eventType: 'presence.changed',
                aggregateType: 'presence',
                aggregateId: $pid,
                actorAccountId: null,
                actorPersonId: $pid,
                payload: ['personId' => $pid, 'status' => $online ? 'online' : 'offline', 'lastSeenAt' => $now],
                targetPersonIds: null,
                createdAt: $now,
                ephemeral: true,
            );
            $this->redis->publish(self::REDIS_CHANNEL, json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // Presence failure must not crash API endpoints.
        }
    }

    /**
     * @param list<string> $personIds
     * @return list<array{personId: string, status: string, lastSeenAt: ?string}>
     */
    public function getPresences(string $organizationId, array $personIds): array
    {
        $orgId = self::assertUuid($organizationId, 'organizationId');
        $clean = [];
        foreach ($personIds as $id) {
            $trim = trim($id);
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $trim) === 1) {
                $clean[] = strtolower($trim);
            }
        }
        $clean = array_values(array_unique(array_slice($clean, 0, 100)));
        if ($clean === []) {
            return [];
        }

        $keys = array_map(
            static fn(string $pid): string => sprintf('presence:org:%s:person:%s', $orgId, $pid),
            $clean,
        );

        /** @var list<mixed> $rawValues */
        $rawValues = [];
        try {
            $rawValues = array_values($this->redis->mget($keys));
        } catch (Throwable) {
            $rawValues = [];
        }

        /** @var list<array{personId: string, status: string, lastSeenAt: ?string}> $result */
        $result = [];
        foreach ($clean as $idx => $pid) {
            $status = 'offline';
            $lastSeenAt = null;
            $raw = $rawValues[$idx] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $status = ($decoded['status'] ?? '') === 'online' ? 'online' : 'offline';
                    $rawLastSeen = $decoded['lastSeenAt'] ?? null;
                    $lastSeenAt = is_string($rawLastSeen) ? $rawLastSeen : null;
                }
            }
            $result[] = [
                'personId' => $pid,
                'status' => $status,
                'lastSeenAt' => $lastSeenAt,
            ];
        }

        return $result;
    }

    public function setTyping(
        string $accountId,
        string $personId,
        string $organizationId,
        string $roomId,
        bool $typing,
    ): void {
        $orgId = self::assertUuid($organizationId, 'organizationId');
        $rid = self::assertUuid($roomId, 'roomId');
        $channel = 'room:' . $rid;

        if (!$this->authorizer->canSubscribe($accountId, $personId, $orgId, $channel)) {
            throw new HttpError(403, 'room_access_denied');
        }

        $personName = $this->db->createCommand(
            "SELECT COALESCE(NULLIF(display_name, ''), NULLIF(CONCAT_WS(' ', given_name, family_name), ''), 'User')
             FROM core.persons WHERE id = CAST(:id AS uuid)",
            [':id' => $personId],
        )->queryScalar();
        $displayName = is_string($personName) && $personName !== '' ? $personName : 'User';

        $now = gmdate(DATE_ATOM);
        $key = sprintf('typing:room:%s:person:%s', $rid, $personId);
        try {
            if ($typing) {
                $this->redis->setex($key, 6, $displayName);
            } else {
                $this->redis->del([$key]);
            }

            $event = new RealtimeEvent(
                seq: 0,
                eventId: 'ephemeral-typing-' . bin2hex(random_bytes(6)),
                organizationId: $orgId,
                channel: $channel,
                eventType: 'chat.typing',
                aggregateType: 'chat_room',
                aggregateId: $rid,
                actorAccountId: $accountId,
                actorPersonId: $personId,
                payload: [
                    'roomId' => $rid,
                    'personId' => $personId,
                    'displayName' => $displayName,
                    'typing' => $typing,
                    'expiresAt' => gmdate(DATE_ATOM, time() + 6),
                ],
                targetPersonIds: null,
                createdAt: $now,
                ephemeral: true,
            );
            $this->redis->publish(self::REDIS_CHANNEL, json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // Ephemeral typing failure must not break REST calls.
        }
    }

    private function signingKey(): string
    {
        if ($this->ticketSecret !== '') {
            return $this->ticketSecret;
        }
        $envKey = getenv('AUTH_TOTP_ENCRYPTION_KEY') ?: getenv('REALTIME_TICKET_SECRET');
        return is_string($envKey) && $envKey !== '' ? $envKey : 'platform_realtime_ws_secret_v1';
    }

    /** @param array<string, mixed> $row */
    private static function hydrateRow(array $row): RealtimeEvent
    {
        $payloadDecoded = json_decode((string) ($row['payloadJson'] ?? '{}'), true);
        /** @var array<string, mixed> $payload */
        $payload = is_array($payloadDecoded) ? $payloadDecoded : [];

        $targetsDecoded = isset($row['targetsJson']) && is_string($row['targetsJson'])
            ? json_decode($row['targetsJson'], true)
            : null;
        /** @var list<string>|null $targets */
        $targets = is_array($targetsDecoded)
            ? array_values(array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $targetsDecoded))
            : null;

        return new RealtimeEvent(
            seq: (int) ($row['seq'] ?? 0),
            eventId: (string) ($row['eventId'] ?? ''),
            organizationId: (string) ($row['organizationId'] ?? ''),
            channel: (string) ($row['channel'] ?? ''),
            eventType: (string) ($row['eventType'] ?? ''),
            aggregateType: (string) ($row['aggregateType'] ?? ''),
            aggregateId: (string) ($row['aggregateId'] ?? ''),
            actorAccountId: isset($row['actorAccountId']) ? (string) $row['actorAccountId'] : null,
            actorPersonId: isset($row['actorPersonId']) ? (string) $row['actorPersonId'] : null,
            payload: $payload,
            targetPersonIds: $targets,
            createdAt: (string) ($row['createdAt'] ?? ''),
        );
    }
}
