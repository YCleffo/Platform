<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Application\StorageFileAdapter;
use App\Storage\Application\StorageObjectAdapter;
use Aws\Exception\AwsException;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Committed deletion outboxes are reconciled against references under the backup lock. */
final readonly class BinaryCleanup
{
    public function __construct(private ConnectionInterface $db, private StorageFileAdapter $files,
        private ?S3Objects $objects = null, private ?S3Config $config = null, private ?S3ClientFactory $clients = null) {}

    public function queueOrphans(): int
    {
        $this->assertCommitted();
        $queued = $this->reconcileTransfers();
        if (!$this->files instanceof StorageObjectAdapter) return $queued;
        foreach ($this->files->keysBefore(new DateTimeImmutable('-1 day')) as $key) {
            if ($this->referenced($key)) continue;
            $queued += $this->db->createCommand('INSERT INTO storage.binary_cleanup(storage_key)
                VALUES(CAST(:key AS uuid)) ON CONFLICT(storage_key) DO NOTHING', [':key' => $key])->execute();
        }
        if ($this->objects !== null && $this->config !== null) {
            foreach ($this->objects->objectsBefore($this->config->bucketStorage, 'branding/', new DateTimeImmutable('-1 day')) as $object) {
                $current = $this->db->createCommand("SELECT 1 FROM core.system_settings
                    WHERE setting_key='platform.favicon' AND :key='branding/'||(value->>'id')",
                    [':key' => $object['key']])->queryScalar();
                if ($current === false) $queued += $this->queueObject($object['key']);
            }
            foreach ($this->objects->objectsBefore($this->config->bucketStorage, 'staging/', new DateTimeImmutable('-1 day')) as $object) {
                $live = $this->db->createCommand("SELECT 1 FROM storage.transfers WHERE staging_key=:key
                    AND status IN ('pending','uploading','verifying')", [':key' => $object['key']])->queryScalar();
                if ($live === false) $queued += $this->queueObject($object['key']);
            }
        }
        return $queued + $this->queueMultipartOrphans();
    }

    public function drain(int $limit = 100): int
    {
        $this->assertCommitted();
        $limit = max(1, min(1000, $limit));
        $completed = 0;
        $rows = $this->db->createCommand('SELECT storage_key::text FROM storage.binary_cleanup
            WHERE completed_at IS NULL ORDER BY queued_at LIMIT :limit', [':limit' => $limit])->queryAll();
        foreach ($rows as $row) {
            $key = $row['storage_key'];
            try {
                $completed += $this->db->transaction(function () use ($key): int {
                    $this->lockBackup();
                    $pending = $this->db->createCommand('SELECT storage_key FROM storage.binary_cleanup
                        WHERE storage_key=CAST(:key AS uuid) AND completed_at IS NULL FOR UPDATE', [':key' => $key])->queryScalar();
                    if ($pending === false || $this->referenced($key)) return 0;
                    $this->files->delete($key);
                    $this->db->createCommand('UPDATE storage.binary_cleanup SET completed_at=now(),attempts=attempts+1,last_error=NULL
                        WHERE storage_key=CAST(:key AS uuid)', [':key' => $key])->execute();
                    return 1;
                });
            } catch (\Throwable) {
                $this->db->createCommand("UPDATE storage.binary_cleanup SET attempts=attempts+1,last_error='object_delete_failed'
                    WHERE storage_key=CAST(:key AS uuid) AND completed_at IS NULL", [':key' => $key])->execute();
            }
        }
        return $completed + $this->drainStaging($limit);
    }

    public function reconcileTransfers(): int
    {
        $this->assertCommitted();
        return $this->db->transaction(function (): int {
            $rows = $this->db->createCommand("SELECT id::text,staging_key,multipart_upload_id,final_storage_key::text,direction
                FROM storage.transfers WHERE status IN ('pending','uploading','verifying') AND expires_at<=now()
                ORDER BY expires_at LIMIT 1000 FOR UPDATE SKIP LOCKED")->queryAll();
            foreach ($rows as $row) {
                $this->db->createCommand("UPDATE storage.transfers SET status='expired',reserved_bytes=0,reserved_files=0,updated_at=now()
                    WHERE id=CAST(:id AS uuid)", [':id' => $row['id']])->execute();
                if ($row['direction'] !== 'upload') continue;
                $this->queueObject($row['staging_key'], $row['multipart_upload_id'], $row['id'], 960);
                $this->db->createCommand('INSERT INTO storage.binary_cleanup(storage_key) VALUES(CAST(:key AS uuid))
                    ON CONFLICT(storage_key) DO NOTHING', [':key' => $row['final_storage_key']])->execute();
            }
            return count($rows);
        });
    }

    private function drainStaging(int $limit): int
    {
        if ($this->objects === null || $this->config === null || $this->clients === null) return 0;
        $rows = $this->db->createCommand('SELECT object_key FROM storage.object_cleanup
            WHERE completed_at IS NULL AND not_before<=now() ORDER BY queued_at LIMIT :limit', [':limit' => $limit])->queryAll();
        $completed = 0;
        foreach ($rows as $item) {
            $key = $item['object_key'];
            try {
                $completed += $this->db->transaction(function () use ($key): int {
                    $this->lockBackup();
                    $job = $this->db->createCommand('SELECT multipart_upload_id FROM storage.object_cleanup
                        WHERE object_key=:key AND completed_at IS NULL FOR UPDATE', [':key' => $key])->queryOne();
                    if (!is_array($job)) return 0;
                    if (str_starts_with($key, 'objects/') && $this->referenced(substr($key, strlen('objects/')))) return 0;
                    if (str_starts_with($key, 'branding/')) {
                        $current = $this->db->createCommand("SELECT 1 FROM core.system_settings
                            WHERE setting_key='platform.favicon' AND value->>'id'=:id",
                            [':id' => substr($key, strlen('branding/'))])->queryScalar();
                        if ($current !== false) return 0;
                    }
                    $active = $this->db->createCommand("SELECT 1 FROM storage.transfers WHERE staging_key=:key
                        AND status IN ('pending','uploading','verifying')", [':key' => $key])->queryScalar();
                    if ($active !== false) return 0;
                    if ($job['multipart_upload_id'] !== null) {
                        try {
                            $this->clients->internal()->abortMultipartUpload(['Bucket' => $this->config->bucketStorage,
                                'Key' => $key, 'UploadId' => $job['multipart_upload_id']]);
                        } catch (AwsException $error) {
                            if ($error->getAwsErrorCode() !== 'NoSuchUpload') throw $error;
                        }
                    }
                    $this->objects->delete($this->config->bucketStorage, $key);
                    $this->db->createCommand('UPDATE storage.object_cleanup SET completed_at=now(),attempts=attempts+1,last_error=NULL
                        WHERE object_key=:key', [':key' => $key])->execute();
                    return 1;
                });
            } catch (\Throwable) {
                $this->db->createCommand("UPDATE storage.object_cleanup SET attempts=attempts+1,last_error='staging_delete_failed'
                    WHERE object_key=:key AND completed_at IS NULL", [':key' => $key])->execute();
            }
        }
        return $completed;
    }

    private function queueMultipartOrphans(): int
    {
        if ($this->clients === null || $this->config === null) return 0;
        $queued = 0;
        $pages = $this->clients->internal()->getPaginator('ListMultipartUploads', [
            'Bucket' => $this->config->bucketStorage, 'Prefix' => 'staging/', 'MaxUploads' => 1000,
        ]);
        foreach ($pages as $page) {
            foreach ($page['Uploads'] ?? [] as $upload) {
                if (new DateTimeImmutable((string) $upload['Initiated']) >= new DateTimeImmutable('-1 day')) continue;
                $active = $this->db->createCommand("SELECT 1 FROM storage.transfers WHERE staging_key=:key
                    AND multipart_upload_id=:upload AND status IN ('pending','uploading','verifying')",
                    [':key' => $upload['Key'], ':upload' => $upload['UploadId']])->queryScalar();
                if ($active === false) $queued += $this->queueObject((string) $upload['Key'], (string) $upload['UploadId']);
            }
        }
        return $queued;
    }

    private function queueObject(string $key, ?string $upload = null, ?string $transfer = null, int $delay = 0): int
    {
        return $this->db->createCommand('INSERT INTO storage.object_cleanup(object_key,multipart_upload_id,transfer_id,not_before)
            VALUES(:key,:upload,CAST(:transfer AS uuid),now()+CAST(:delay AS int)*interval \'1 second\')
            ON CONFLICT(object_key) DO UPDATE SET multipart_upload_id=EXCLUDED.multipart_upload_id,
                transfer_id=EXCLUDED.transfer_id,not_before=EXCLUDED.not_before,completed_at=NULL,last_error=NULL
                WHERE storage.object_cleanup.completed_at IS NOT NULL',
            [':key' => $key, ':upload' => $upload, ':transfer' => $transfer, ':delay' => $delay])->execute();
    }

    private function referenced(string $key): bool
    {
        return $this->db->createCommand("SELECT 1 WHERE
            EXISTS(SELECT 1 FROM storage.versions WHERE storage_key=CAST(:key AS uuid)) OR
            EXISTS(SELECT 1 FROM storage.transfers WHERE final_storage_key=CAST(:key AS uuid)
                AND direction='upload' AND status IN ('pending','uploading','verifying'))",
            [':key' => $key])->queryScalar() !== false;
    }

    private function lockBackup(): void
    {
        $this->db->createCommand("SELECT pg_advisory_xact_lock(hashtextextended('platform.storage.backup',0))")->queryScalar();
    }

    private function assertCommitted(): void
    {
        if ($this->db->getTransaction() !== null) throw new DomainException('Cleanup requires committed metadata.');
    }
}
