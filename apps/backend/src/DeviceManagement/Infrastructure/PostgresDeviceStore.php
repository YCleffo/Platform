<?php

declare(strict_types=1);

namespace App\DeviceManagement\Infrastructure;

use App\DeviceManagement\Application\DeviceError;
use App\DeviceManagement\Application\DeviceStore;
use App\DeviceManagement\Application\Scope;
use App\DeviceManagement\Application\SessionTransitions;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresDeviceStore implements DeviceStore
{
    private const DEVICE = 'SELECT d.id::text,d.organization_id::text AS "organizationId",d.owner_account_id::text AS "ownerAccountId",
        d.classroom_id::text AS "classroomId",d.workstation_id::text AS "workstationId",d.name,d.number,d.status,
        d.enrollment_state AS "enrollmentState",d.last_seen_at::text AS "lastSeenAt",d.created_at::text AS "createdAt",
        c.name AS "classroomName" FROM device_management.devices d
        LEFT JOIN device_management.classrooms c ON c.id=d.classroom_id';

    public function __construct(private ConnectionInterface $db) {}

    private static function where(string $alias): string
    {
        return "$alias.organization_id=CAST(:org AS uuid) AND $alias.owner_account_id=CAST(:actor AS uuid)";
    }

    /** @return array<string,string> */
    private static function owner(Scope $scope): array
    {
        return [':org' => $scope->organizationId, ':actor' => $scope->accountId];
    }

    /**
     * @param array<non-empty-string,string|int|null> $params
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->db->createCommand($sql, $params)->queryOne();
        /** @var array<string,mixed>|false $row */
        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * @param array<non-empty-string,string|int|null> $params
     * @return list<array<string,mixed>>
     */
    private function all(string $sql, array $params): array
    {
        $rows = $this->db->createCommand($sql, $params)->queryAll();
        /** @var list<array<string,mixed>> $rows */
        return array_map(self::normalize(...), $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if (str_ends_with($key, 'At') && is_string($value)) {
                $row[$key] = (new DateTimeImmutable($value))->format('Y-m-d\TH:i:s.uP');
            }
        }
        return $row;
    }

    public function classroom(Scope $scope, string $id): ?array
    {
        return $this->one(
            'SELECT c.id::text,c.name FROM device_management.classrooms c WHERE ' . self::where('c') . ' AND c.id=CAST(:id AS uuid)',
            [...self::owner($scope), ':id' => $id],
        );
    }

    public function classrooms(Scope $scope): array
    {
        return $this->all(
            'SELECT c.id::text,c.name,c.created_at::text AS "createdAt" FROM device_management.classrooms c
            WHERE ' . self::where('c') . ' ORDER BY c.created_at,c.id LIMIT 101',
            self::owner($scope),
        );
    }

    public function createClassroom(Scope $scope, string $name): array
    {
        return $this->one(
            'INSERT INTO device_management.classrooms(organization_id,owner_account_id,owner_person_id,name)
            VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),:name)
            RETURNING id::text,name,created_at::text AS "createdAt"',
            [...self::owner($scope), ':person' => $scope->personId, ':name' => $name],
        ) ?? throw new DeviceError(409, 'write_not_confirmed');
    }

    public function devices(Scope $scope, int $offset): array
    {
        return $this->all(
            self::DEVICE . ' WHERE ' . self::where('d') . ' ORDER BY d.created_at,d.id LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':offset' => $offset],
        );
    }

    public function device(Scope $scope, string $id): ?array
    {
        return $this->one(
            self::DEVICE . ' WHERE ' . self::where('d') . ' AND d.id=CAST(:id AS uuid)',
            [...self::owner($scope), ':id' => $id],
        );
    }

    public function deviceSessions(Scope $scope, string $deviceId): array
    {
        return $this->all(
            'SELECT s.id::text,s.student_id::text AS "studentId",s.status,s.desired_status AS "desiredStatus",
            s.started_at::text AS "startedAt",s.ended_at::text AS "endedAt",s.created_at::text AS "createdAt",
            s.organization_id::text AS "organizationId",s.owner_account_id::text AS "ownerAccountId"
            FROM device_management.device_sessions s
            JOIN device_management.devices d ON d.id=s.device_id
            WHERE ' . self::where('d') . ' AND s.device_id=CAST(:device AS uuid)
            ORDER BY s.created_at,s.id LIMIT 51',
            [...self::owner($scope), ':device' => $deviceId],
        );
    }

    public function createDevice(Scope $scope, ?string $classroomId, string $name, string $number): array
    {
        return $this->db->transaction(function () use ($scope, $classroomId, $name, $number): array {
            if ($classroomId !== null) {
                $this->classroom($scope, $classroomId) ?? throw new DeviceError(404, 'not_found');
            }
            return $this->one(
                'INSERT INTO device_management.devices(organization_id,owner_account_id,owner_person_id,classroom_id,name,number)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),:classroom,:name,:number)
                RETURNING id::text,name,number,status,enrollment_state AS "enrollmentState",created_at::text AS "createdAt"',
                [...self::owner($scope), ':person' => $scope->personId, ':classroom' => $classroomId,
                    ':name' => $name, ':number' => $number],
            ) ?? throw new DeviceError(409, 'write_not_confirmed');
        });
    }

    public function createEnrollment(Scope $scope, string $deviceId, string $codeHash, string $expiresAt): array
    {
        return $this->one(
            'INSERT INTO device_management.device_enrollments(device_id,code_hash,expires_at)
            SELECT d.id,:code,:expires FROM device_management.devices d WHERE ' . self::where('d') . ' AND d.id=CAST(:device AS uuid)
            RETURNING id::text,device_id::text AS "deviceId",expires_at::text AS "expiresAt"',
            [...self::owner($scope), ':device' => $deviceId, ':code' => $codeHash, ':expires' => $expiresAt],
        ) ?? throw new DeviceError(404, 'not_found');
    }

    public function findEnrollment(string $codeHash): ?array
    {
        return $this->one(
            'SELECT e.id::text,e.device_id::text AS "deviceId",e.expires_at::text AS "expiresAt",e.used_at::text AS "usedAt"
            FROM device_management.device_enrollments e WHERE e.code_hash=:hash',
            [':hash' => $codeHash],
        );
    }

    public function confirmEnrollment(string $enrollmentId, string $deviceId, string $credentialHash): array
    {
        return $this->db->transaction(function () use ($enrollmentId, $deviceId, $credentialHash): array {
            $enrollment = $this->one(
                'SELECT id::text,device_id::text AS "deviceId",expires_at::text AS "expiresAt",used_at::text AS "usedAt"
                FROM device_management.device_enrollments WHERE id=CAST(:id AS uuid) FOR UPDATE',
                [':id' => $enrollmentId],
            );
            if ($enrollment === null || $enrollment['deviceId'] !== $deviceId) {
                throw new DeviceError(404, 'not_found');
            }
            if ($enrollment['usedAt'] !== null
                || new DateTimeImmutable((string) $enrollment['expiresAt']) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                throw new DeviceError(409, 'enrollment_invalid');
            }
            $device = $this->one(
                "SELECT id::text,enrollment_state AS \"enrollmentState\" FROM device_management.devices
                WHERE id=CAST(:device AS uuid) FOR UPDATE",
                [':device' => $deviceId],
            );
            if ($device === null || $device['enrollmentState'] === 'revoked') {
                throw new DeviceError(409, 'device_revoked');
            }
            $this->db->createCommand(
                'UPDATE device_management.device_enrollments SET used_at=now() WHERE id=CAST(:id AS uuid)',
                [':id' => $enrollmentId],
            )->execute();
            // Re-enrollment rotates the device key: previous active credentials stop working immediately.
            $this->db->createCommand(
                'UPDATE device_management.device_credentials SET revoked_at=now()
                WHERE device_id=CAST(:device AS uuid) AND revoked_at IS NULL',
                [':device' => $deviceId],
            )->execute();
            $credential = $this->db->createCommand(
                'INSERT INTO device_management.device_credentials(device_id,credential_hash)
                VALUES(CAST(:device AS uuid),:hash) RETURNING id::text',
                [':device' => $deviceId, ':hash' => $credentialHash],
            )->queryScalar();
            $this->db->createCommand(
                "UPDATE device_management.devices SET enrollment_state='confirmed',status='AVAILABLE' WHERE id=CAST(:device AS uuid)",
                [':device' => $deviceId],
            )->execute();
            return ['deviceId' => $deviceId, 'credentialId' => (string) $credential];
        });
    }

    public function credentialDevice(string $credentialHash): ?array
    {
        return $this->one(
            'SELECT c.device_id::text AS "deviceId",c.id::text AS "credentialId",d.organization_id::text AS "organizationId",
            d.owner_account_id::text AS "ownerAccountId",d.status,d.enrollment_state AS "enrollmentState"
            FROM device_management.device_credentials c JOIN device_management.devices d ON d.id=c.device_id
            WHERE c.credential_hash=:hash AND c.revoked_at IS NULL AND d.enrollment_state<>\'revoked\'',
            [':hash' => $credentialHash],
        );
    }

    public function revokeDevice(Scope $scope, string $deviceId): void
    {
        $this->db->transaction(function () use ($scope, $deviceId): void {
            $this->db->createCommand(
                "UPDATE device_management.devices SET enrollment_state='revoked'
                WHERE id=CAST(:device AS uuid) AND organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid)",
                [...self::owner($scope), ':device' => $deviceId],
            )->execute();
            $this->db->createCommand(
                'UPDATE device_management.device_credentials SET revoked_at=now()
                WHERE device_id=CAST(:device AS uuid) AND revoked_at IS NULL',
                [':device' => $deviceId],
            )->execute();
        });
    }

    public function heartbeat(string $deviceId, string $state): void
    {
        $this->db->transaction(function () use ($deviceId, $state): void {
            $this->db->createCommand(
                'INSERT INTO device_management.device_heartbeats(device_id,state) VALUES(CAST(:device AS uuid),CAST(:state AS jsonb))',
                [':device' => $deviceId, ':state' => $state],
            )->execute();
            $this->db->createCommand(
                "UPDATE device_management.devices SET last_seen_at=now()
                WHERE id=CAST(:device AS uuid) AND enrollment_state<>'revoked'",
                [':device' => $deviceId],
            )->execute();
        });
    }

    public function activeSession(string $deviceId): ?array
    {
        return $this->one(
            "SELECT s.id::text,s.student_id::text AS \"studentId\",s.status,s.desired_status AS \"desiredStatus\",
            s.started_at::text AS \"startedAt\",s.organization_id::text AS \"organizationId\"
            FROM device_management.device_sessions s WHERE s.device_id=CAST(:device AS uuid) AND s.status NOT IN ('CLOSED','FAILED')
            ORDER BY s.created_at DESC LIMIT 1",
            [':device' => $deviceId],
        );
    }

    public function startSession(Scope $scope, string $deviceId, string $studentId, array $student): array
    {
        return $this->db->transaction(function () use ($scope, $deviceId, $studentId, $student): array {
            $device = $this->one(
                self::DEVICE . ' WHERE ' . self::where('d') . ' AND d.id=CAST(:id AS uuid) FOR UPDATE OF d',
                [...self::owner($scope), ':id' => $deviceId],
            ) ?? throw new DeviceError(404, 'not_found');
            if ($device['enrollmentState'] === 'revoked') {
                throw new DeviceError(409, 'device_revoked');
            }
            $existing = $this->activeSession($deviceId);
            if ($existing !== null) {
                if (SessionTransitions::isTransitionBlocking((string) $existing['status'])) {
                    throw new DeviceError(409, 'sync_pending');
                }
                throw new DeviceError(409, 'device_busy');
            }
            $session = $this->one(
                'INSERT INTO device_management.device_sessions(device_id,student_id,organization_id,owner_account_id,status,desired_status)
                VALUES(CAST(:device AS uuid),CAST(:student AS uuid),CAST(:org AS uuid),CAST(:actor AS uuid),\'AUTHORIZED\',\'AUTHORIZED\')
                RETURNING id::text,device_id::text AS "deviceId",student_id::text AS "studentId",status,desired_status AS "desiredStatus",
                started_at::text AS "startedAt"',
                [...self::owner($scope), ':device' => $deviceId, ':student' => $studentId],
            ) ?? throw new DeviceError(409, 'device_busy');
            $this->queueCommand($deviceId, 'start_session', [
                'sessionId' => (string) $session['id'], 'studentId' => $studentId, 'personId' => (string) $student['personId'],
            ], (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes')->format(DateTimeImmutable::ATOM));
            return [...$session, 'studentPersonId' => $student['personId'], 'studentName' => $student['name']];
        });
    }

    public function session(Scope $scope, string $deviceId, string $sessionId): ?array
    {
        return $this->one(
            'SELECT s.id::text,s.device_id::text AS "deviceId",s.student_id::text AS "studentId",s.status,
            s.desired_status AS "desiredStatus",s.started_at::text AS "startedAt",s.ended_at::text AS "endedAt"
            FROM device_management.device_sessions s JOIN device_management.devices d ON d.id=s.device_id
            WHERE ' . self::where('d') . ' AND s.device_id=CAST(:device AS uuid) AND s.id=CAST(:session AS uuid)',
            [...self::owner($scope), ':device' => $deviceId, ':session' => $sessionId],
        );
    }

    public function requestSessionEnd(Scope $scope, string $deviceId, string $sessionId): void
    {
        $this->db->transaction(function () use ($scope, $deviceId, $sessionId): void {
            $session = $this->one(
                'SELECT id::text,status,desired_status AS "desiredStatus" FROM device_management.device_sessions
                WHERE device_id=CAST(:device AS uuid) AND id=CAST(:session AS uuid) AND organization_id=CAST(:org AS uuid)
                AND owner_account_id=CAST(:actor AS uuid) FOR UPDATE',
                [...self::owner($scope), ':device' => $deviceId, ':session' => $sessionId],
            ) ?? throw new DeviceError(404, 'not_found');
            if (SessionTransitions::terminal((string) $session['status'])) {
                return;
            }
            if ($session['desiredStatus'] === 'ENDING') {
                return; // The command is redelivered until acknowledged; do not duplicate it.
            }
            $this->db->createCommand(
                "UPDATE device_management.device_sessions SET desired_status='ENDING' WHERE id=CAST(:session AS uuid)",
                [':session' => $sessionId],
            )->execute();
            $this->queueCommand($deviceId, 'end_session', ['sessionId' => $sessionId],
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes')->format(DateTimeImmutable::ATOM));
        });
    }

    public function transitionSession(string $deviceId, string $sessionId, string $status): array
    {
        return $this->db->transaction(function () use ($deviceId, $sessionId, $status): array {
            $session = $this->one(
                'SELECT id::text,status,desired_status AS "desiredStatus" FROM device_management.device_sessions
                WHERE device_id=CAST(:device AS uuid) AND id=CAST(:session AS uuid) FOR UPDATE',
                [':device' => $deviceId, ':session' => $sessionId],
            ) ?? throw new DeviceError(404, 'not_found');
            $current = (string) $session['status'];
            if ($current === $status) {
                return ['sessionId' => $sessionId, 'deviceId' => $deviceId, 'status' => $status];
            }
            if (!SessionTransitions::allowed($current, $status)) {
                throw new DeviceError(409, 'invalid_transition');
            }
            if ($status === 'ENDING' && $session['desiredStatus'] !== 'ENDING') {
                throw new DeviceError(409, 'end_not_requested');
            }
            if ($status === 'CLOSED' && $current !== 'CLEANUP') {
                throw new DeviceError(409, 'storage_not_confirmed');
            }
            $endedAt = SessionTransitions::terminal($status) ? 'now()' : 'NULL';
            $this->db->createCommand(
                "UPDATE device_management.device_sessions SET status=:status,ended_at=$endedAt,desired_status=:desired
                WHERE id=CAST(:session AS uuid)",
                [':status' => $status, ':session' => $sessionId,
                    ':desired' => SessionTransitions::terminal($status) ? $status : $session['desiredStatus']],
            )->execute();
            $deviceStatus = match ($status) {
                'AUTHORIZED', 'PREPARING' => 'RESERVED',
                'WINDOWS_PROFILE_READY', 'SYNCING_IN' => 'PREPARING',
                'ACTIVE' => 'ACTIVE',
                'ENDING', 'SYNCING_OUT' => 'SYNCING_OUT',
                'STORAGE_CONFIRMED', 'CLEANUP' => 'CLEANUP',
                'SYNC_PENDING' => 'RECOVERY_REQUIRED',
                'RECOVERY_REQUIRED' => 'RECOVERY_REQUIRED',
                'FAILED' => 'ERROR',
                default => 'AVAILABLE',
            };
            $this->db->createCommand("UPDATE device_management.devices SET status=:status WHERE id=CAST(:device AS uuid)",
                [':status' => $deviceStatus, ':device' => $deviceId])->execute();
            return ['sessionId' => $sessionId, 'deviceId' => $deviceId, 'status' => $status];
        });
    }

    public function queueCommand(string $deviceId, string $kind, array $payload, string $expiresAt): array
    {
        return $this->one(
            'INSERT INTO device_management.device_commands(device_id,kind,payload,expires_at)
            VALUES(CAST(:device AS uuid),:kind,CAST(:payload AS jsonb),:expires)
            RETURNING id::text,device_id::text AS "deviceId",kind,payload,expires_at::text AS "expiresAt",state',
            [':device' => $deviceId, ':kind' => $kind,
                ':payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ':expires' => $expiresAt],
        ) ?? throw new DeviceError(409, 'write_not_confirmed');
    }

    public function pendingCommands(string $deviceId): array
    {
        return $this->db->transaction(function () use ($deviceId): array {
            $this->db->createCommand(
                "UPDATE device_management.device_commands SET state='expired'
                WHERE device_id=CAST(:device AS uuid) AND state IN ('queued','delivered') AND expires_at<now()",
                [':device' => $deviceId],
            )->execute();
            $commands = $this->all(
                "SELECT id::text,kind,payload,expires_at::text AS \"expiresAt\",state FROM device_management.device_commands
                WHERE device_id=CAST(:device AS uuid) AND state IN ('queued','delivered') ORDER BY id LIMIT 20",
                [':device' => $deviceId],
            );
            foreach ($commands as $command) {
                if ($command['state'] === 'delivered') {
                    continue; // Redeliver unacknowledged commands so a lost acknowledgment is retried.
                }
                $this->db->createCommand(
                    "UPDATE device_management.device_commands SET state='delivered',delivered_at=now() WHERE id=CAST(:id AS uuid)",
                    [':id' => $command['id']],
                )->execute();
            }
            return $commands;
        });
    }

    public function command(string $deviceId, string $commandId): ?array
    {
        return $this->one(
            'SELECT id::text,kind,payload,state,expires_at::text AS "expiresAt" FROM device_management.device_commands
            WHERE device_id=CAST(:device AS uuid) AND id=CAST(:command AS uuid)',
            [':device' => $deviceId, ':command' => $commandId],
        );
    }

    public function acknowledgeCommand(string $deviceId, string $commandId): array
    {
        return $this->db->transaction(function () use ($deviceId, $commandId): array {
            $command = $this->one(
                'SELECT id::text,state FROM device_management.device_commands
                WHERE device_id=CAST(:device AS uuid) AND id=CAST(:command AS uuid) FOR UPDATE',
                [':device' => $deviceId, ':command' => $commandId],
            ) ?? throw new DeviceError(404, 'not_found');
            if ($command['state'] === 'acknowledged') {
                return ['commandId' => $commandId, 'acknowledged' => true];
            }
            $this->db->createCommand(
                "UPDATE device_management.device_commands SET state='acknowledged',acknowledged_at=now()
                WHERE id=CAST(:command AS uuid)",
                [':command' => $commandId],
            )->execute();
            return ['commandId' => $commandId, 'acknowledged' => true];
        });
    }

    public function recordEvent(string $deviceId, string $kind, array $payload): void
    {
        $this->db->createCommand(
            'INSERT INTO device_management.device_events(device_id,kind,payload) VALUES(CAST(:device AS uuid),:kind,CAST(:payload AS jsonb))',
            [':device' => $deviceId, ':kind' => $kind, ':payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        )->execute();
    }

    public function deviceEvents(Scope $scope, string $deviceId, int $offset): array
    {
        return $this->all(
            'SELECT e.id::text,e.kind,e.payload,e.created_at::text AS "createdAt" FROM device_management.device_events e
            JOIN device_management.devices d ON d.id=e.device_id
            WHERE ' . self::where('d') . ' AND e.device_id=CAST(:device AS uuid)
            ORDER BY e.id DESC LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':device' => $deviceId, ':offset' => $offset],
        );
    }

    public function markSeen(string $deviceId): void
    {
        $this->db->createCommand("UPDATE device_management.devices SET last_seen_at=now() WHERE id=CAST(:device AS uuid)",
            [':device' => $deviceId])->execute();
    }
}
