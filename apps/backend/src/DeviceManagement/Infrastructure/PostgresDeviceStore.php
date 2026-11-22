<?php

declare(strict_types=1);

namespace App\DeviceManagement\Infrastructure;

use App\DeviceManagement\Application\DeviceError;
use App\DeviceManagement\Application\DeviceStore;
use App\DeviceManagement\Application\Scope;
use App\DeviceManagement\Application\SessionTransitions;
use App\DeviceManagement\Application\SessionEvidence;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresDeviceStore implements DeviceStore
{
    private const DEVICE_STATUS = "CASE WHEN d.status='AVAILABLE' AND (
        d.enrollment_state<>'confirmed' OR d.last_seen_at IS NULL OR d.last_seen_at < now() - interval '90 seconds'
        OR (d.agent_state->>'machineReady') IS DISTINCT FROM 'true'
        OR coalesce(d.agent_state->>'hasUnfinishedDocuments','false')='true'
    ) THEN 'OFFLINE' ELSE d.status END";

    private const DEVICE = 'SELECT d.id::text,d.organization_id::text AS "organizationId",d.owner_account_id::text AS "ownerAccountId",
        d.classroom_id::text AS "classroomId",d.workstation_id::text AS "workstationId",d.name,d.number,' . self::DEVICE_STATUS . ' AS status,
        d.enrollment_state AS "enrollmentState",d.last_seen_at::text AS "lastSeenAt",d.created_at::text AS "createdAt",
        d.state_version AS "stateVersion",c.name AS "classroomName" FROM device_management.devices d
        LEFT JOIN device_management.classrooms c ON c.id=d.classroom_id';

    public function __construct(private ConnectionInterface $db, private bool $allowTestAdapter = false) {}

    private static function where(string $alias): string
    {
        return "$alias.organization_id=CAST(:org AS uuid) AND $alias.owner_account_id=CAST(:actor AS uuid)";
    }

    /** @return array<non-empty-string,string> */
    private static function owner(Scope $scope): array
    {
        return [':org' => $scope->organizationId, ':actor' => $scope->accountId];
    }

    /**
     * @param array<array-key,mixed> $params
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->db->createCommand($sql, $params)->queryOne();
        /** @var array<string,mixed>|false $row */
        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * @param array<array-key,mixed> $params
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
        foreach (['payload','agent_state','evidence'] as $jsonField) {
            $fieldVal = $row[$jsonField] ?? null;
            if (is_string($fieldVal)) {
                $decoded = json_decode($fieldVal, true);
                $row[$jsonField] = is_array($decoded) ? $decoded : [];
            }
        }
        if (isset($row['stateVersion'])) {
            $row['stateVersion'] = (int) $row['stateVersion'];
        }
        return $row;
    }

    public function classroom(Scope $scope, string $id): ?array
    {
        return $this->one(
            'SELECT c.id::text,c.name,c.archived_at::text AS "archivedAt" FROM device_management.classrooms c WHERE ' . self::where('c') . ' AND c.id=CAST(:id AS uuid)',
            [...self::owner($scope), ':id' => $id],
        );
    }

    public function classrooms(Scope $scope, int $offset = 0, ?string $search = null, bool $archived = false): array
    {
        return $this->all(
            'SELECT c.id::text,c.name,c.created_at::text AS "createdAt",c.archived_at::text AS "archivedAt"
            FROM device_management.classrooms c WHERE ' . self::where('c')
            . ($archived ? ' AND c.archived_at IS NOT NULL' : ' AND c.archived_at IS NULL')
            . ($search !== null ? ' AND c.name ILIKE :search' : '') . ' ORDER BY c.created_at,c.id LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':offset' => $offset, ...($search !== null ? [':search' => '%' . $search . '%'] : [])],
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

    public function updateClassroom(Scope $scope, string $id, string $name): array
    {
        return $this->one(
            'UPDATE device_management.classrooms SET name=:name
            WHERE organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid) AND id=CAST(:id AS uuid)
            RETURNING id::text,name,created_at::text AS "createdAt"',
            [...self::owner($scope), ':id' => $id, ':name' => $name],
        ) ?? throw new DeviceError(404, 'not_found');
    }

    public function archiveClassroom(Scope $scope, string $id): array
    {
        return $this->db->transaction(function () use ($scope, $id): array {
            $this->one('SELECT c.id::text FROM device_management.classrooms c WHERE ' . self::where('c')
                . ' AND c.id=CAST(:id AS uuid) FOR UPDATE', [...self::owner($scope), ':id' => $id])
                ?? throw new DeviceError(404, 'not_found');
            $devices = $this->all('SELECT d.id::text FROM device_management.devices d WHERE ' . self::where('d')
                . ' AND d.classroom_id=CAST(:id AS uuid) ORDER BY d.id FOR UPDATE', [...self::owner($scope), ':id' => $id]);
            foreach ($devices as $device) {
                if ($this->activeSession((string) $device['id']) !== null) {
                    throw new DeviceError(409, 'classroom_busy');
                }
            }
            // Preserve historical placement. Archived classrooms cannot accept new assignments.
            return $this->one(
                'UPDATE device_management.classrooms SET archived_at=coalesce(archived_at,now())
                WHERE id=CAST(:id AS uuid) RETURNING id::text,name,created_at::text AS "createdAt",archived_at::text AS "archivedAt"',
                [':id' => $id],
            ) ?? throw new DeviceError(409, 'write_not_confirmed');
        });
    }

    /** @return array<string,mixed> */
    public function restoreClassroom(Scope $scope, string $id): array
    {
        return $this->one(
            'UPDATE device_management.classrooms c SET archived_at=NULL WHERE ' . self::where('c')
            . ' AND c.id=CAST(:id AS uuid) RETURNING id::text,name,created_at::text AS "createdAt",archived_at::text AS "archivedAt"',
            [...self::owner($scope), ':id' => $id],
        ) ?? throw new DeviceError(404, 'not_found');
    }

    public function devices(
        Scope $scope,
        int $offset,
        ?string $classroomId = null,
        ?string $status = null,
        ?string $search = null,
    ): array {
        $clauses = [self::where('d')];
        $params = [...self::owner($scope), ':offset' => $offset];

        if ($classroomId !== null) {
            $clauses[] = 'd.classroom_id = CAST(:classroomId AS uuid)';
            $params[':classroomId'] = $classroomId;
        }

        if ($status !== null && $status !== '') {
            $clauses[] = self::DEVICE_STATUS . ' = :status';
            $params[':status'] = $status;
        }

        if ($search !== null && trim($search) !== '') {
            $clauses[] = '(d.name ILIKE :search OR d.number ILIKE :search)';
            $params[':search'] = '%' . trim($search) . '%';
        }

        return $this->all(
            self::DEVICE . ' WHERE ' . implode(' AND ', $clauses) . ' ORDER BY d.created_at,d.id LIMIT 51 OFFSET :offset',
            $params,
        );
    }

    public function device(Scope $scope, string $id): ?array
    {
        return $this->one(
            self::DEVICE . ' WHERE ' . self::where('d') . ' AND d.id=CAST(:id AS uuid)',
            [...self::owner($scope), ':id' => $id],
        );
    }

    public function deviceSessions(Scope $scope, string $deviceId, int $offset = 0): array
    {
        return $this->all(
            'SELECT s.id::text,s.device_id::text AS "deviceId",s.student_id::text AS "studentId",
            st.person_id::text AS "studentPersonId",coalesce(p.display_name,\'\') AS "studentName",
            s.status,s.desired_status AS "desiredStatus",s.classroom_id::text AS "classroomId",
            s.started_at::text AS "startedAt",s.ended_at::text AS "endedAt",s.created_at::text AS "createdAt"
            FROM device_management.device_sessions s JOIN device_management.devices d ON d.id=s.device_id
            JOIN education.students st ON st.id=s.student_id LEFT JOIN core.persons p ON p.id=st.person_id
            WHERE ' . self::where('d') . ' AND s.device_id=CAST(:device AS uuid)
            ORDER BY s.created_at DESC,s.id DESC LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':device' => $deviceId, ':offset' => $offset],
        );
    }

    public function createDevice(Scope $scope, ?string $classroomId, string $name, string $number): array
    {
        return $this->db->transaction(function () use ($scope, $classroomId, $name, $number): array {
            if ($classroomId !== null) {
                $classroom = $this->one('SELECT c.id::text,c.archived_at::text AS "archivedAt" FROM device_management.classrooms c WHERE ' . self::where('c') . ' AND c.id=CAST(:id AS uuid) FOR SHARE', [...self::owner($scope), ':id' => $classroomId]) ?? throw new DeviceError(404, 'not_found');
                if ($classroom['archivedAt'] !== null) {
                    throw new DeviceError(409, 'classroom_archived');
                }
            }
            if ($classroomId !== null && $number !== '') {
                $duplicate = $this->one(
                    'SELECT id::text FROM device_management.devices
                    WHERE organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid) AND classroom_id=CAST(:classroom AS uuid)
                    AND number=:number',
                    [...self::owner($scope), ':classroom' => $classroomId, ':number' => $number],
                );
                if ($duplicate !== null) {
                    throw new DeviceError(409, 'duplicate_device_number');
                }
            }
            $created = $this->one(
                'INSERT INTO device_management.devices(organization_id,owner_account_id,owner_person_id,classroom_id,name,number)
                VALUES(CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:person AS uuid),' . ($classroomId !== null ? 'CAST(:classroom AS uuid)' : 'NULL') . ',:name,:number)
                RETURNING id::text,name,number,status,enrollment_state AS "enrollmentState",created_at::text AS "createdAt"',
                [...self::owner($scope), ':person' => $scope->personId, ...($classroomId !== null ? [':classroom' => $classroomId] : []),
                    ':name' => $name, ':number' => $number],
            ) ?? throw new DeviceError(409, 'write_not_confirmed');
            return $this->device($scope, (string) $created['id']) ?? throw new DeviceError(409, 'write_not_confirmed');
        });
    }

    public function updateDevice(Scope $scope, string $id, ?string $classroomId, string $name, string $number): array
    {
        return $this->db->transaction(function () use ($scope, $id, $classroomId, $name, $number): array {
            $existing = $this->one(self::DEVICE . ' WHERE ' . self::where('d') . ' AND d.id=CAST(:id AS uuid) FOR UPDATE OF d', [...self::owner($scope), ':id' => $id]) ?? throw new DeviceError(404, 'not_found');
            if (($existing['classroomId'] !== $classroomId || $existing['number'] !== $number) && $this->activeSession($id) !== null) {
                throw new DeviceError(409, 'device_busy');
            }
            if ($classroomId !== null) {
                $classroom = $this->one('SELECT c.id::text,c.archived_at::text AS "archivedAt" FROM device_management.classrooms c WHERE ' . self::where('c') . ' AND c.id=CAST(:id AS uuid) FOR SHARE', [...self::owner($scope), ':id' => $classroomId]) ?? throw new DeviceError(404, 'not_found');
                if ($classroom['archivedAt'] !== null) {
                    throw new DeviceError(409, 'classroom_archived');
                }
            }
            if ($classroomId !== null && $number !== '') {
                $duplicate = $this->one(
                    'SELECT id::text FROM device_management.devices
                    WHERE organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid) AND classroom_id=CAST(:classroom AS uuid)
                    AND number=:number AND id<>CAST(:id AS uuid)',
                    [...self::owner($scope), ':classroom' => $classroomId, ':number' => $number, ':id' => $id],
                );
                if ($duplicate !== null) {
                    throw new DeviceError(409, 'duplicate_device_number');
                }
            }
            $classroomSql = $classroomId !== null ? 'CAST(:classroom AS uuid)' : 'NULL';
            $updated = $this->one(
                "UPDATE device_management.devices
                SET classroom_id=$classroomSql, workstation_id=NULL, name=:name, number=:number,state_version=state_version+1
                WHERE organization_id=CAST(:org AS uuid) AND owner_account_id=CAST(:actor AS uuid) AND id=CAST(:id AS uuid)
                RETURNING id::text,name,number,status,enrollment_state AS \"enrollmentState\",created_at::text AS \"createdAt\"",
                [...self::owner($scope), ':id' => $id, ...($classroomId !== null ? [':classroom' => $classroomId] : []),
                    ':name' => $name, ':number' => $number],
            ) ?? throw new DeviceError(409, 'write_not_confirmed');
            return $this->device($scope, (string) $updated['id']) ?? throw new DeviceError(409, 'write_not_confirmed');
        });
    }

    public function createEnrollment(Scope $scope, string $deviceId, string $codeHash, string $expiresAt): array
    {
        if ($this->activeSession($deviceId) !== null) {
            throw new DeviceError(409, 'device_busy');
        }
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
                "UPDATE device_management.devices SET enrollment_state='confirmed',
                status=CASE WHEN status='AVAILABLE' THEN 'OFFLINE' ELSE status END,
                last_seen_at=NULL,agent_state='{}',state_version=state_version+1 WHERE id=CAST(:device AS uuid)",
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
            $this->lockOwnedDevice($scope, $deviceId, null);
            if ($this->activeSession($deviceId) !== null) {
                throw new DeviceError(409, 'device_busy');
            }
            $this->db->createCommand(
                "UPDATE device_management.devices SET enrollment_state='revoked',state_version=state_version+1
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
                'INSERT INTO device_management.device_heartbeats(device_id,received_at,state) VALUES(CAST(:device AS uuid),clock_timestamp(),CAST(:state AS jsonb))',
                [':device' => $deviceId, ':state' => $state],
            )->execute();
            $this->db->createCommand(
                "UPDATE device_management.devices SET last_seen_at=now(),agent_state=CAST(:state AS jsonb),
                    status=CASE WHEN status IN ('OFFLINE','AVAILABLE') AND NOT EXISTS (SELECT 1 FROM device_management.device_sessions s WHERE s.device_id=devices.id AND s.status<>'CLOSED')
                        THEN CASE WHEN (CAST(:state AS jsonb)->>'machineReady')='true' AND coalesce(CAST(:state AS jsonb)->>'hasUnfinishedDocuments','false')<>'true' THEN 'AVAILABLE' ELSE 'OFFLINE' END ELSE status END
                WHERE id=CAST(:device AS uuid) AND enrollment_state<>'revoked'",
                [':device' => $deviceId, ':state' => $state],
            )->execute();
        });
    }

    public function activeSession(string $deviceId): ?array
    {
        return $this->one(
            "SELECT s.id::text,s.student_id::text AS \"studentId\",s.status,s.desired_status AS \"desiredStatus\",
            s.started_at::text AS \"startedAt\",s.organization_id::text AS \"organizationId\"
            FROM device_management.device_sessions s WHERE s.device_id=CAST(:device AS uuid) AND s.status<>'CLOSED'
            ORDER BY s.created_at DESC LIMIT 1",
            [':device' => $deviceId],
        );
    }

    public function startSession(Scope $scope, string $deviceId, string $studentId, array $student, ?int $stateVersion = null): array
    {
        return $this->db->transaction(function () use ($scope, $deviceId, $studentId, $student, $stateVersion): array {
            $device = $this->one(
                self::DEVICE . ' WHERE ' . self::where('d') . ' AND d.id=CAST(:id AS uuid) FOR UPDATE OF d',
                [...self::owner($scope), ':id' => $deviceId],
            ) ?? throw new DeviceError(404, 'not_found');
            if ($stateVersion !== null && (int) $device['stateVersion'] !== $stateVersion) {
                throw new DeviceError(409, 'state_version_conflict');
            }
            if ($device['enrollmentState'] === 'revoked') {
                throw new DeviceError(409, 'device_revoked');
            }
            if ($device['enrollmentState'] !== 'confirmed') {
                throw new DeviceError(409, 'device_not_confirmed');
            }
            if ($device['classroomId'] !== null) {
                $classroom = $this->one('SELECT id::text,archived_at::text AS "archivedAt" FROM device_management.classrooms WHERE id=CAST(:id AS uuid) FOR SHARE', [':id' => $device['classroomId']]);
                if ($classroom === null || $classroom['archivedAt'] !== null) {
                    throw new DeviceError(409, 'classroom_archived');
                }
            }
            $existing = $this->activeSession($deviceId);
            if ($existing !== null) {
                if (SessionTransitions::isTransitionBlocking((string) $existing['status'])) {
                    throw new DeviceError(409, 'sync_pending');
                }
                throw new DeviceError(409, 'device_busy');
            }
            if ($device['status'] !== 'AVAILABLE' || $device['lastSeenAt'] === null || new DateTimeImmutable((string) $device['lastSeenAt']) < new DateTimeImmutable('-90 seconds')) {
                throw new DeviceError(409, 'device_not_ready');
            }
            $session = $this->one(
                'INSERT INTO device_management.device_sessions(device_id,student_id,organization_id,owner_account_id,classroom_id,status,desired_status)
                VALUES(CAST(:device AS uuid),CAST(:student AS uuid),CAST(:org AS uuid),CAST(:actor AS uuid),CAST(:classroom AS uuid),\'AUTHORIZED\',\'AUTHORIZED\')
                RETURNING id::text,device_id::text AS "deviceId",student_id::text AS "studentId",status,desired_status AS "desiredStatus",
                started_at::text AS "startedAt"',
                [...self::owner($scope), ':device' => $deviceId, ':student' => $studentId, ':classroom' => $device['classroomId'] ?? null],
            ) ?? throw new DeviceError(409, 'device_busy');
            $this->db->createCommand("UPDATE device_management.devices SET status='RESERVED',state_version=state_version+1 WHERE id=CAST(:device AS uuid)", [':device' => $deviceId])->execute();
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
            if ($session['status'] !== 'ACTIVE') {
                throw new DeviceError(409, 'session_not_active');
            }
            $this->db->createCommand(
                "UPDATE device_management.device_sessions SET desired_status='ENDING' WHERE id=CAST(:session AS uuid)",
                [':session' => $sessionId],
            )->execute();
            $this->db->createCommand('UPDATE device_management.devices SET state_version=state_version+1 WHERE id=CAST(:device AS uuid)', [':device' => $deviceId])->execute();
            $this->queueCommand(
                $deviceId,
                'end_session',
                ['sessionId' => $sessionId],
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes')->format(DateTimeImmutable::ATOM),
            );
        });
    }

    public function transitionSession(string $deviceId, string $sessionId, string $status, array $evidence = []): array
    {
        return $this->db->transaction(function () use ($deviceId, $sessionId, $status, $evidence): array {
            $this->one('SELECT id::text FROM device_management.devices WHERE id=CAST(:device AS uuid) FOR UPDATE', [':device' => $deviceId]) ?? throw new DeviceError(404, 'not_found');
            $session = $this->one(
                'SELECT id::text,status,desired_status AS "desiredStatus",organization_id::text AS "organizationId",student_id::text AS "studentId" FROM device_management.device_sessions
                WHERE device_id=CAST(:device AS uuid) AND id=CAST(:session AS uuid) FOR UPDATE',
                [':device' => $deviceId, ':session' => $sessionId],
            ) ?? throw new DeviceError(404, 'not_found');
            $current = (string) $session['status'];
            if (!SessionTransitions::allowed($current, $status)) {
                throw new DeviceError(409, 'invalid_transition');
            }
            SessionEvidence::assertTransition($status, $evidence, $this->allowTestAdapter);
            if ($current === $status) {
                if (!SessionTransitions::terminal($status)) {
                    $this->db->createCommand('UPDATE device_management.device_sessions SET evidence=evidence || CAST(:evidence AS jsonb) WHERE id=CAST(:session AS uuid)', [':evidence' => json_encode((object) $evidence, JSON_THROW_ON_ERROR), ':session' => $sessionId])->execute();
                }
                return ['sessionId' => $sessionId, 'deviceId' => $deviceId, 'status' => $status];
            }
            if (in_array($status, ['ENDING', 'SYNCING_OUT'], true) && $session['desiredStatus'] !== 'ENDING') {
                throw new DeviceError(409, 'end_not_requested');
            }
            if ($status === 'CLOSED' && $current !== 'CLEANUP') {
                throw new DeviceError(409, 'storage_not_confirmed');
            }
            if (in_array($status, ['STORAGE_CONFIRMED', 'CLEANUP', 'CLOSED'], true)) {
                $this->assertStorageConfirmed($deviceId, $session);
            }
            $endedAt = SessionTransitions::terminal($status) ? 'now()' : 'NULL';
            $this->db->createCommand(
                "UPDATE device_management.device_sessions SET status=:status,ended_at=$endedAt,desired_status=:desired,evidence=evidence || CAST(:evidence AS jsonb)
                WHERE id=CAST(:session AS uuid)",
                [':status' => $status, ':session' => $sessionId, ':evidence' => json_encode((object) $evidence, JSON_THROW_ON_ERROR),
                    ':desired' => SessionTransitions::terminal($status) ? $status : $session['desiredStatus']],
            )->execute();
            $deviceStatus = match ($status) {
                'AUTHORIZED', 'PREPARING' => 'RESERVED',
                'WINDOWS_PROFILE_READY' => 'PREPARING',
                'SYNCING_IN' => 'SYNCING_IN',
                'ACTIVE' => 'ACTIVE',
                'ENDING', 'SYNCING_OUT' => 'SYNCING_OUT',
                'STORAGE_CONFIRMED', 'CLEANUP' => 'CLEANUP',
                'SYNC_PENDING' => 'RECOVERY_REQUIRED',
                'RECOVERY_REQUIRED' => 'RECOVERY_REQUIRED',
                'FAILED' => 'ERROR',
                default => 'AVAILABLE',
            };
            $this->db->createCommand(
                "UPDATE device_management.devices SET status=:status,state_version=state_version+1 WHERE id=CAST(:device AS uuid)",
                [':status' => $deviceStatus, ':device' => $deviceId],
            )->execute();
            $this->recordEvent($deviceId, 'session_state', ['sessionId' => $sessionId, 'from' => $current, 'status' => $status]);
            return ['sessionId' => $sessionId, 'deviceId' => $deviceId, 'status' => $status];
        });
    }

    private function assertStorageConfirmed(string $deviceId, array $session): void
    {
        // Serialize with Storage operations so a pending manifest is visible before cleanup.
        $space = $this->one(
            'SELECT id::text FROM storage.spaces
            WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid) FOR UPDATE',
            [':org' => $session['organizationId'], ':student' => $session['studentId']],
        );
        if ($space === null) {
            throw new DeviceError(409, 'storage_not_confirmed');
        }

        $confirmation = $this->one("SELECT m.id::text FROM storage.manifests m
            WHERE m.space_id=CAST(:space AS uuid) AND m.device_id=CAST(:device AS uuid) AND m.status='confirmed'
            AND m.confirmed_at >= (SELECT MIN(e.created_at) FROM device_management.device_events e
                WHERE e.device_id=m.device_id AND e.kind='session_state' AND e.payload->>'sessionId'=:session
                AND e.payload->>'status'='SYNCING_OUT')
            AND NOT EXISTS(SELECT 1 FROM storage.manifests pending WHERE pending.space_id=m.space_id AND pending.status='pending')
            AND NOT EXISTS(SELECT 1 FROM storage.manifests archived WHERE archived.space_id=m.space_id
                AND archived.status='accepted' AND archived.version>=m.version)
            LIMIT 1", [':space' => $space['id'], ':device' => $deviceId, ':session' => $session['id']]);
        if ($confirmation === null) {
            throw new DeviceError(409, 'storage_not_confirmed');
        }
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
                WHERE device_id=CAST(:device AS uuid)
                AND (state='queued' OR (state='delivered' AND delivered_at < now() - interval '30 seconds'))
                ORDER BY id LIMIT 20",
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
                'SELECT id::text,state,expires_at::text AS "expiresAt" FROM device_management.device_commands
                WHERE device_id=CAST(:device AS uuid) AND id=CAST(:command AS uuid) FOR UPDATE',
                [':device' => $deviceId, ':command' => $commandId],
            ) ?? throw new DeviceError(404, 'not_found');
            if ($command['state'] === 'acknowledged') {
                return ['commandId' => $commandId, 'acknowledged' => true];
            }
            if ($command['state'] === 'expired' || new DateTimeImmutable((string) $command['expiresAt']) <= new DateTimeImmutable()) {
                throw new DeviceError(409, 'command_expired');
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
            'INSERT INTO device_management.device_events(device_id,kind,payload,created_at) VALUES(CAST(:device AS uuid),:kind,CAST(:payload AS jsonb),clock_timestamp())',
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
        $this->db->createCommand(
            "UPDATE device_management.devices SET last_seen_at=now() WHERE id=CAST(:device AS uuid)",
            [':device' => $deviceId],
        )->execute();
    }
    /** @return array<string,mixed> */
    public function agentInfo(string $deviceId): array
    {
        return $this->one('SELECT d.id::text,d.number,d.name,d.enrollment_state AS "enrollmentState",c.name AS "classroomName"
            FROM device_management.devices d LEFT JOIN device_management.classrooms c ON c.id=d.classroom_id
            WHERE d.id=CAST(:device AS uuid)', [':device' => $deviceId]) ?? throw new DeviceError(404, 'not_found');
    }

    /** @return list<array<string,mixed>> */
    public function commands(Scope $scope, string $deviceId, int $offset = 0): array
    {
        $rows = $this->all(
            'SELECT c.id::text,c.kind,c.payload,c.state AS status,c.created_at::text AS "createdAt",
            c.expires_at::text AS "expiresAt",c.acknowledged_at::text AS "acknowledgedAt"
            FROM device_management.device_commands c JOIN device_management.devices d ON d.id=c.device_id
            WHERE ' . self::where('d') . ' AND d.id=CAST(:device AS uuid) ORDER BY c.created_at DESC,c.id DESC LIMIT 51 OFFSET :offset',
            [...self::owner($scope), ':device' => $deviceId, ':offset' => $offset],
        );
        return array_map(static function (array $row): array {
            $row['payload'] = is_string($row['payload']) ? (json_decode($row['payload'], true) ?: []) : $row['payload'];
            return $row;
        }, $rows);
    }

    /** @return array<string,mixed> */
    public function lifecycleDetails(Scope $scope, string $deviceId): array
    {
        $device = $this->device($scope, $deviceId) ?? throw new DeviceError(404, 'not_found');
        $session = $this->activeSession($deviceId);
        $row = $this->one('SELECT agent_state,evidence FROM device_management.devices d
            LEFT JOIN device_management.device_sessions s ON s.id=(SELECT id FROM device_management.device_sessions
                WHERE device_id=d.id AND status<>\'CLOSED\' ORDER BY created_at DESC LIMIT 1)
            WHERE d.id=CAST(:device AS uuid)', [':device' => $deviceId]) ?? [];
        $decode = static function (mixed $value): array {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];
            }
            return is_array($value) ? $value : [];
        };
        $state = $decode($row['agent_state'] ?? null);
        $evidence = $decode($row['evidence'] ?? null);
        $error = $this->one("SELECT payload,created_at::text AS at FROM device_management.device_events
            WHERE device_id=CAST(:device AS uuid) AND kind IN ('agent_error','session_error') ORDER BY created_at DESC,id DESC LIMIT 1", [':device' => $deviceId]);
        $payload = $decode($error['payload'] ?? null);
        $lastError = $evidence['error'] ?? ($error === null ? null : ['code' => (string) ($payload['code'] ?? 'agent_error'),
            'message' => substr((string) ($payload['message'] ?? ''), 0, 500), 'at' => (new DateTimeImmutable((string) $error['at']))->format(DateTimeImmutable::ATOM)]);
        $online = $device['enrollmentState'] === 'confirmed' && $device['lastSeenAt'] !== null
            && new DateTimeImmutable((string) $device['lastSeenAt']) >= new DateTimeImmutable('-90 seconds');
        $archived = $device['classroomId'] !== null && ($this->classroom($scope, (string) $device['classroomId'])['archivedAt'] ?? null) !== null;
        $recovery = $session !== null && in_array($session['status'], ['SYNC_PENDING','RECOVERY_REQUIRED','FAILED'], true);
        return ['availableActions' => \App\DeviceManagement\Application\DeviceAvailableActions::forDevice($device, $session, $online, $archived),
            'commands' => array_slice($this->commands($scope, $deviceId), 0, 50), 'progress' => $evidence['progress'] ?? $state['progress'] ?? null,
            'lastError' => $lastError, 'recovery' => $recovery ? ['required' => true, 'reason' => $session['status'], 'retryAllowed' => $online] : null,
            'stateVersion' => (int) $device['stateVersion'], 'online' => $online, 'agentVersion' => $state['version'] ?? null,
            'testAdapter' => $this->allowTestAdapter && ($evidence['testAdapter'] ?? false) === true];
    }

    private function lockOwnedDevice(Scope $scope, string $deviceId, ?int $stateVersion): array
    {
        $device = $this->one(
            self::DEVICE . ' WHERE ' . self::where('d') . ' AND d.id=CAST(:device AS uuid) FOR UPDATE OF d',
            [...self::owner($scope), ':device' => $deviceId],
        ) ?? throw new DeviceError(404, 'not_found');
        if ($device['enrollmentState'] === 'revoked') {
            throw new DeviceError(409, 'device_revoked');
        }
        if ($stateVersion !== null && (int) $device['stateVersion'] !== $stateVersion) {
            throw new DeviceError(409, 'state_version_conflict');
        }
        return $device;
    }

    /** @return array<string,mixed> */
    public function cancelAssignment(Scope $scope, string $deviceId, string $sessionId, ?int $stateVersion): array
    {
        return $this->db->transaction(function () use ($scope, $deviceId, $sessionId, $stateVersion): array {
            $this->lockOwnedDevice($scope, $deviceId, $stateVersion);
            $session = $this->session($scope, $deviceId, $sessionId) ?? throw new DeviceError(404, 'not_found');
            if ($session['status'] === 'CLOSED') {
                return ['sessionId' => $sessionId, 'cancelled' => true];
            }
            if ($session['status'] !== 'AUTHORIZED') {
                throw new DeviceError(409, 'assignment_already_started');
            }
            $this->db->createCommand("UPDATE device_management.device_sessions SET status='CLOSED',desired_status='CLOSED',ended_at=now()
                WHERE id=CAST(:session AS uuid)", [':session' => $sessionId])->execute();
            $this->db->createCommand(
                "UPDATE device_management.device_commands SET state='expired' WHERE device_id=CAST(:device AS uuid)
                AND kind='start_session' AND payload->>'sessionId'=:session AND state IN ('queued','delivered')",
                [':device' => $deviceId, ':session' => $sessionId],
            )->execute();
            $this->db->createCommand("UPDATE device_management.devices SET status='OFFLINE',state_version=state_version+1 WHERE id=CAST(:device AS uuid)", [':device' => $deviceId])->execute();
            $this->recordEvent($deviceId, 'assignment_cancelled', ['sessionId' => $sessionId]);
            return ['sessionId' => $sessionId, 'cancelled' => true];
        });
    }

    /** @return array<string,mixed> */
    public function retryRecovery(Scope $scope, string $deviceId, ?int $stateVersion): array
    {
        return $this->db->transaction(function () use ($scope, $deviceId, $stateVersion): array {
            $this->lockOwnedDevice($scope, $deviceId, $stateVersion);
            $session = $this->activeSession($deviceId) ?? throw new DeviceError(404, 'not_found');
            if (!in_array($session['status'], ['SYNC_PENDING','RECOVERY_REQUIRED','FAILED'], true)) {
                throw new DeviceError(409, 'recovery_not_required');
            }
            $this->db->createCommand("UPDATE device_management.device_sessions SET status=CASE WHEN status='FAILED' THEN 'RECOVERY_REQUIRED' ELSE status END,desired_status='ENDING',ended_at=NULL
                WHERE id=CAST(:session AS uuid)", [':session' => $session['id']])->execute();
            $command = $this->one(
                "SELECT id::text FROM device_management.device_commands WHERE device_id=CAST(:device AS uuid)
                AND kind='end_session' AND payload->>'sessionId'=:session AND state IN ('queued','delivered') AND expires_at>now()",
                [':device' => $deviceId, ':session' => $session['id']],
            );
            if ($command === null) {
                $command = $this->queueCommand($deviceId, 'end_session', ['sessionId' => $session['id']], (new DateTimeImmutable('+15 minutes'))->format(DateTimeImmutable::ATOM));
            }
            $this->db->createCommand(
                'UPDATE device_management.device_commands SET authorized_by_account_id=CAST(:actor AS uuid) WHERE id=CAST(:command AS uuid)',
                [':actor' => $scope->accountId, ':command' => $command['id']],
            )->execute();
            $this->db->createCommand('UPDATE device_management.devices SET state_version=state_version+1 WHERE id=CAST(:device AS uuid)', [':device' => $deviceId])->execute();
            $this->recordEvent($deviceId, 'recovery_requested', ['sessionId' => $session['id']]);
            return ['sessionId' => $session['id'], 'retrying' => true];
        });
    }

    /** @return array<string,mixed> */
    public function requestLocalSessionEnd(string $deviceId, string $sessionId): array
    {
        $device = $this->one('SELECT organization_id::text AS org,owner_account_id::text AS actor,owner_person_id::text AS person
            FROM device_management.devices WHERE id=CAST(:device AS uuid) AND enrollment_state=\'confirmed\'', [':device' => $deviceId])
            ?? throw new DeviceError(404, 'not_found');
        $active = $this->activeSession($deviceId);
        if ($active === null || $active['id'] !== $sessionId || $active['status'] !== 'ACTIVE') {
            throw new DeviceError(409, 'session_not_active');
        }
        $scope = new Scope((string) $device['org'], (string) $device['actor'], (string) $device['person'], 'ru-RU', 'UTC');
        $this->requestSessionEnd($scope, $deviceId, $sessionId);
        $this->recordEvent($deviceId, 'local_end_requested', ['sessionId' => $sessionId]);
        return ['sessionId' => $sessionId, 'ending' => true];
    }

}
