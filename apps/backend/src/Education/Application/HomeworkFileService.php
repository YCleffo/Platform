<?php

declare(strict_types=1);

namespace App\Education\Application;

use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class HomeworkFileService
{
    public function __construct(private ConnectionInterface $db, private HomeworkService $homework,
        private FileStore $files, private Mutation $mutations) {}

    public function upload(Scope $scope, string $id, UploadedFileInterface $upload): array
    {
        return $this->persistUpload($scope, $id, fn(): StoredFile => $this->files->store($upload, false));
    }

    public function uploadStored(Scope $scope, string $id, StoredFile $file): array
    {
        return $this->persistUpload($scope, $id, static fn(): StoredFile => $file);
    }

    public function assertUploadAllowed(Scope $scope, string $id): void
    {
        $this->db->transaction(fn(): array => $this->editable($scope, Input::uuid($id)));
    }

    private function persistUpload(Scope $scope, string $id, callable $provide): array
    {
        $stored = null;
        try {
            return $this->mutations->run($scope, $scope->studentId === null ? 'homework_upload_material' : 'homework_upload_submission',
                function () use ($scope, $id, $provide, &$stored): array {
                    $detail = $this->editable($scope, Input::uuid($id));
                    $recipient = $scope->studentId === null ? null : $detail['recipient']['id'];
                    $count = (int) $this->db->createCommand('SELECT count(*) FROM education.homework_files f
                        WHERE assignment_id=CAST(:id AS uuid) AND ((:recipient::uuid IS NULL AND purpose=\'material\')
                        OR EXISTS(SELECT 1 FROM education.homework_draft_files d WHERE d.file_id=f.id AND d.recipient_id=:recipient::uuid))',
                        [':id' => $id, ':recipient' => $recipient])->queryScalar();
                    if ($count >= 20) throw new EducationError(422, 'attachment_limit');
                    $stored = $provide();
                    $file = $this->db->createCommand('INSERT INTO education.homework_files(organization_id,assignment_id,recipient_id,
                        owner_person_id,purpose,name,mime,size_bytes,sha256,storage_key)
                        VALUES(:org::uuid,:assignment::uuid,:recipient::uuid,:person::uuid,:purpose,:name,:mime,:size,:hash,:key::uuid)
                        RETURNING id::text,name,mime,size_bytes AS "sizeBytes",created_at::text AS "createdAt"',
                        [':org' => $scope->organizationId, ':assignment' => $id, ':recipient' => $recipient, ':person' => $scope->personId,
                            ':purpose' => $recipient === null ? 'material' : 'submission', ':name' => $stored->name, ':mime' => $stored->mime,
                            ':size' => $stored->size, ':hash' => $stored->sha256, ':key' => $stored->key])->queryOne();
                    if (!is_array($file)) throw new EducationError(503, 'file_store_unavailable');
                    if ($recipient !== null) $this->db->createCommand('INSERT INTO education.homework_draft_files(recipient_id,file_id)
                        VALUES(:recipient::uuid,:file::uuid)', [':recipient' => $recipient, ':file' => $file['id']])->execute();
                    return $file;
                });
        } catch (\Throwable $error) {
            if ($stored instanceof StoredFile) $this->files->remove($stored->key);
            throw $error;
        }
    }

    public function unlink(Scope $scope, string $id, string $fileId): array
    {
        if ($scope->studentId === null) throw new EducationError(403, 'access_denied');
        return $this->mutations->run($scope, 'homework_delete_submission_file', function () use ($scope, $id, $fileId): array {
            $detail = $this->editable($scope, Input::uuid($id));
            $this->db->createCommand('DELETE FROM education.homework_draft_files WHERE recipient_id=:recipient::uuid AND file_id=:file::uuid',
                [':recipient' => $detail['recipient']['id'], ':file' => Input::uuid($fileId)])->execute();
            // Submitted version links and immutable bytes survive removing the draft attachment.
            return $this->homework->read('student_detail', $scope, [], $id);
        });
    }

    public function fileForDownload(Scope $scope, string $fileId): array
    {
        $file = $this->db->createCommand('SELECT * FROM education.homework_files WHERE id=:id::uuid AND organization_id=:org::uuid',
            [':id' => Input::uuid($fileId), ':org' => $scope->organizationId])->queryOne();
        if (!is_array($file)) throw new EducationError(404, 'file_not_found');
        $detail = $this->homework->read($scope->studentId === null ? 'teacher_detail' : 'student_detail', $scope, [], (string) $file['assignment_id']);
        if ($file['recipient_id'] !== null) {
            if ($scope->studentId !== null && $file['recipient_id'] !== $detail['recipient']['id']) throw new EducationError(404, 'file_not_found');
            if ($scope->studentId === null) {
                if ($this->db->createCommand('SELECT 1 FROM education.homework_version_files WHERE file_id=CAST(:id AS uuid)',
                    [':id' => $fileId])->queryScalar() === false) throw new EducationError(404, 'file_not_found');
                // Enforce the concrete student's privacy scope, beyond assignment-level authorization.
                $submission = $this->db->createCommand('SELECT id::text FROM education.homework_submissions WHERE recipient_id=:id::uuid',
                    [':id' => $file['recipient_id']])->queryScalar();
                if (!is_string($submission)) throw new EducationError(404, 'file_not_found');
                $this->homework->read('teacher_submission_detail', $scope, [], $submission);
            }
        }
        return $file;
    }

    public function download(Scope $scope, string $fileId): array
    {
        $file = $this->fileForDownload($scope, $fileId);
        return ['stream' => $this->files->open((string) $file['storage_key'], (int) $file['size_bytes'], (string) $file['sha256']),
            'name' => (string) $file['name'], 'mime' => (string) $file['mime'], 'size' => (int) $file['size_bytes'], 'sha256' => (string) $file['sha256']];
    }

    private function editable(Scope $scope, string $id): array
    {
        // All file mutations lock the assignment first, matching publish/submit lock order.
        $this->db->createCommand('SELECT id FROM education.homework_assignments WHERE id=:id::uuid AND organization_id=:org::uuid FOR UPDATE',
            [':id' => $id, ':org' => $scope->organizationId])->queryOne();
        $detail = $this->homework->read($scope->studentId === null ? 'teacher_detail' : 'student_detail', $scope, [], $id);
        if ($scope->studentId === null) {
            if ($detail['assignment']['status'] !== 'DRAFT') throw new EducationError(409, 'materials_locked');
        } else {
            if ($detail['assignment']['status'] !== 'PUBLISHED' || !in_array($detail['submission']['state'], ['NOT_STARTED','IN_PROGRESS','RETURNED'], true)) {
                throw new EducationError(409, 'submission_not_editable');
            }
            if ($detail['submission']['id'] === null) $detail = $this->homework->write('save_draft', $scope, ['version' => 0, 'text' => ''], $id);
            $this->db->createCommand('SELECT id FROM education.homework_submissions WHERE recipient_id=:id::uuid FOR UPDATE',
                [':id' => $detail['recipient']['id']])->queryOne();
        }
        return $detail;
    }
}
