<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Education\Infrastructure\S3FileStore;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Durable, actor-bound Education transfers. File business rules remain in the existing services. */
final readonly class EducationFileTransfers
{
    public function __construct(
        private ConnectionInterface $db,
        private S3FileStore $files,
        private EducationService $education,
        private HomeworkFileService $homework,
    ) {}

    public function start(Scope $scope, string $kind, ?string $resourceId, array $input): array
    {
        if (array_diff(array_keys($input), ['name', 'sizeBytes', 'sha256', 'avatar', 'lessonId']) !== []) {
            throw new EducationError(422, 'invalid_input');
        }
        $avatar = $input['avatar'] ?? false;
        if (!is_bool($avatar)) {
            throw new EducationError(422, 'invalid_input');
        }
        $lesson = isset($input['lessonId']) ? Input::uuid($input['lessonId']) : null;
        if (!is_string($input['name'] ?? null)) {
            throw new EducationError(422, 'invalid_input');
        }
        $name = Input::text(basename(str_replace('\\', '/', $input['name'])), 240);
        $size = $input['sizeBytes'] ?? null;
        $sha = $input['sha256'] ?? null;
        if (!is_int($size) || $size < 1 || $size > S3FileStore::limit($avatar)
            || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            throw new EducationError(422, 'invalid_file');
        }
        if ($kind === 'homework' && ($avatar || $lesson !== null)) {
            throw new EducationError(422, 'invalid_input');
        }
        return $this->db->transaction(function () use ($scope, $kind, $resourceId, $avatar, $lesson, $name, $size, $sha): array {
            $this->authorizeTarget($scope, $kind, $resourceId, $lesson, $avatar);
            $this->db->createCommand(
                'SELECT pg_advisory_xact_lock(hashtextextended(:key,0))',
                [':key' => 'education.upload.' . $scope->accountId],
            )->queryScalar();
            $pending = (int) $this->db->createCommand(
                'SELECT count(*) FROM education.file_transfers
                WHERE account_id=CAST(:account AS uuid) AND status=\'pending\' AND expires_at>now()',
                [':account' => $scope->accountId],
            )->queryScalar();
            if ($pending >= 20) {
                throw new EducationError(429, 'upload_limit');
            }
            $id = S3FileStore::uuid();
            $this->db->createCommand(
                'INSERT INTO education.file_transfers
                (id,organization_id,account_id,person_id,student_id,kind,resource_id,lesson_id,avatar,name,
                    expected_size,expected_sha256,staging_key,final_key)
                VALUES(CAST(:id AS uuid),CAST(:org AS uuid),CAST(:account AS uuid),CAST(:person AS uuid),CAST(:student AS uuid),
                    :kind,CAST(:resource AS uuid),CAST(:lesson AS uuid),:avatar,:name,:size,:sha,:staging,CAST(:final AS uuid))',
                [':id' => $id, ':org' => $scope->organizationId, ':account' => $scope->accountId, ':person' => $scope->personId,
                    ':student' => $scope->studentId, ':kind' => $kind, ':resource' => $resourceId, ':lesson' => $lesson,
                    ':avatar' => $avatar ? 'true' : 'false', ':name' => $name, ':size' => $size, ':sha' => $sha,
                    ':staging' => 'staging/' . $id . '/' . bin2hex(random_bytes(16)), ':final' => S3FileStore::uuid()],
            )->execute();
            return $this->view($this->record($scope, $id));
        });
    }

    /** Used only to select the existing role-specific scope, after binding to the authenticated account and organization. */
    public function accessKind(string $id, string $account, string $organization): array
    {
        $row = $this->db->createCommand(
            'SELECT kind,student_id::text AS "studentId" FROM education.file_transfers
            WHERE id=CAST(:id AS uuid) AND account_id=CAST(:account AS uuid) AND organization_id=CAST(:org AS uuid)',
            [':id' => Input::uuid($id), ':account' => Input::uuid($account), ':org' => Input::uuid($organization)],
        )->queryOne();
        if (!is_array($row)) {
            throw new EducationError(404, 'not_found');
        }
        return $row;
    }

    public function read(Scope $scope, string $id): array
    {
        $row = $this->record($scope, $id);
        $this->authorizeRecord($scope, $row);
        return $this->view($row);
    }

    public function complete(Scope $scope, string $id): array
    {
        return $this->db->transaction(function () use ($scope, $id): array {
            $row = $this->record($scope, $id, true);
            if ($row['status'] === 'confirmed') {
                $this->authorizeRecord($scope, $row);
                return $this->view($row);
            }
            $this->assertPending($row);
            $avatar = self::boolean($row['avatar']);
            $this->authorizeTarget($scope, $row['kind'], $row['resource_id'], $row['lesson_id'], $avatar);
            $this->db->createCommand('SELECT pg_advisory_xact_lock_shared(hashtextextended(:backup,0)),
                pg_advisory_xact_lock(hashtextextended(:object,0))', [':backup' => 'platform.storage.backup',
                ':object' => 'education.object.objects/' . $row['final_key']])->queryScalar();
            $stored = $this->files->publish(
                $row['staging_key'],
                $row['final_key'],
                $row['name'],
                $avatar,
                (int) $row['expected_size'],
                $row['expected_sha256'],
            );
            $result = $row['kind'] === 'homework'
                ? $this->homework->uploadStored($scope, $row['resource_id'], $stored)
                : $this->education->uploadStored($scope, $stored, $row['lesson_id'], $avatar);
            $this->db->createCommand('UPDATE education.file_transfers SET status=\'confirmed\',result=CAST(:result AS jsonb),
                confirmed_at=now() WHERE id=CAST(:id AS uuid)', [':id' => $id, ':result' => json_encode($result, JSON_THROW_ON_ERROR)])->execute();
            $this->files->queue($row['staging_key']);
            return $this->view($this->record($scope, $id));
        });
    }

    public function abort(Scope $scope, string $id): array
    {
        return $this->db->transaction(function () use ($scope, $id): array {
            $row = $this->record($scope, $id, true);
            $this->authorizeRecord($scope, $row);
            if ($row['status'] === 'confirmed') {
                return $this->view($row);
            }
            $this->db->createCommand('UPDATE education.file_transfers SET status=\'aborted\' WHERE id=CAST(:id AS uuid)', [':id' => $id])->execute();
            $this->files->queue($row['staging_key']);
            $this->files->remove($row['final_key']);
            return $this->view($this->record($scope, $id));
        });
    }

    public function download(Scope $scope, string $id, bool $homework): array
    {
        $file = $homework ? $this->homework->fileForDownload($scope, $id) : $this->education->fileForDownload($scope, $id);
        $key = $homework ? $file['storage_key'] : $file['storageKey'];
        $size = (int) ($homework ? $file['size_bytes'] : $file['sizeBytes']);
        return ['name' => $file['name'], 'mime' => $file['mime'], 'sizeBytes' => $size, 'sha256' => $file['sha256'],
            'request' => $this->files->downloadRequest($key, $file['name'], $file['mime'], $size)];
    }

    private function record(Scope $scope, string $id, bool $lock = false): array
    {
        $row = $this->db->createCommand(
            'SELECT * FROM education.file_transfers WHERE id=CAST(:id AS uuid)
            AND organization_id=CAST(:org AS uuid) AND account_id=CAST(:account AS uuid)
            AND person_id=CAST(:person AS uuid) AND student_id IS NOT DISTINCT FROM CAST(:student AS uuid)' . ($lock ? ' FOR UPDATE' : ''),
            [':id' => Input::uuid($id), ':org' => $scope->organizationId, ':account' => $scope->accountId,
                ':person' => $scope->personId, ':student' => $scope->studentId],
        )->queryOne();
        if (!is_array($row)) {
            throw new EducationError(404, 'not_found');
        }
        return $row;
    }

    private function authorizeTarget(Scope $scope, string $kind, ?string $resource, ?string $lesson, bool $avatar): void
    {
        if ($kind === 'homework') {
            $this->homework->assertUploadAllowed($scope, Input::uuid($resource));
        } elseif ($kind === 'file') {
            $this->education->assertFileUpload($scope, $lesson, $avatar);
        } else {
            throw new EducationError(422, 'invalid_input');
        }
    }

    private function authorizeRecord(Scope $scope, array $row): void
    {
        if ($row['status'] === 'confirmed') {
            $result = is_array($row['result']) ? $row['result'] : json_decode($row['result'], true, flags: JSON_THROW_ON_ERROR);
            if ($row['kind'] === 'homework') {
                $this->homework->fileForDownload($scope, $result['id']);
            } else {
                $this->education->fileForDownload($scope, $result['id']);
            }
            return;
        }
        $this->authorizeTarget($scope, $row['kind'], $row['resource_id'], $row['lesson_id'], self::boolean($row['avatar']));
    }

    private function view(array $row): array
    {
        $pending = $row['status'] === 'pending' && new \DateTimeImmutable($row['expires_at']) > new \DateTimeImmutable();
        $result = $row['result'] === null ? null : (is_array($row['result']) ? $row['result'] : json_decode($row['result'], true, flags: JSON_THROW_ON_ERROR));
        return ['id' => $row['id'], 'status' => $row['status'] === 'pending' && !$pending ? 'expired' : $row['status'],
            'expectedSize' => (int) $row['expected_size'], 'expectedSha256' => $row['expected_sha256'],
            'expiresAt' => (new \DateTimeImmutable($row['expires_at']))->format(DATE_ATOM), 'result' => $result,
            'request' => $pending ? $this->files->uploadRequest($row['staging_key'], (int) $row['expected_size'], $row['expected_sha256']) : null];
    }

    private function assertPending(array $row): void
    {
        if ($row['status'] !== 'pending' || new \DateTimeImmutable($row['expires_at']) <= new \DateTimeImmutable()) {
            throw new EducationError(409, 'upload_expired');
        }
    }

    private static function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
}
