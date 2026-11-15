<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Storage\Infrastructure\{S3Config, S3ClientFactory};
use Yiisoft\Db\Connection\ConnectionInterface;

/** Rechecks committed metadata and the shared backup lock before every physical deletion. */
final readonly class EducationObjectCleanup
{
    public function __construct(private ConnectionInterface $db, private S3Config $config,
        private S3ClientFactory $clients, private S3FileStore $files) {}

    public function run(int $limit = 100): array
    {
        if ($this->db->getTransaction() !== null) throw new \DomainException('Education object cleanup requires committed metadata.');
        $limit = max(1, min(1000, $limit));
        $expired = $this->db->transaction(function () use ($limit): int {
            $rows = $this->db->createCommand('SELECT id::text,staging_key,final_key::text FROM education.file_transfers
                WHERE status=\'pending\' AND expires_at<now() ORDER BY expires_at LIMIT :limit FOR UPDATE SKIP LOCKED', [':limit' => $limit])->queryAll();
            foreach ($rows as $row) {
                $this->files->queue($row['staging_key']);
                $this->files->remove($row['final_key']);
                $this->db->createCommand('UPDATE education.file_transfers SET status=\'aborted\' WHERE id=CAST(:id AS uuid)', [':id' => $row['id']])->execute();
            }
            return count($rows);
        });
        $deleted = 0;
        $rows = $this->db->createCommand('SELECT object_key FROM education.object_cleanup WHERE completed_at IS NULL
            ORDER BY queued_at LIMIT :limit', [':limit' => $limit])->queryColumn();
        foreach ($rows as $key) {
            try {
                $deleted += $this->db->transaction(function () use ($key): int {
                    $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:backup,0)),
                        pg_advisory_xact_lock(hashtextextended(:object,0))',
                        [':backup' => 'platform.storage.backup', ':object' => 'education.object.' . $key])->queryScalar();
                    if ($this->referenced($key)) return 0;
                    $this->clients->internal()->deleteObject(['Bucket' => $this->config->bucketEducation, 'Key' => $key]);
                    $this->db->createCommand('UPDATE education.object_cleanup SET completed_at=now(),attempts=attempts+1,last_error=NULL
                        WHERE object_key=:key', [':key' => $key])->execute();
                    return 1;
                });
            } catch (\Throwable) {
                $this->db->createCommand('UPDATE education.object_cleanup SET attempts=attempts+1,last_error=\'s3_delete_failed\'
                    WHERE object_key=:key', [':key' => $key])->execute();
            }
        }
        return ['expired' => $expired, 'deleted' => $deleted];
    }

    public function queueOrphans(int $limit = 100): int
    {
        if ($this->db->getTransaction() !== null) throw new \DomainException('Education reconciliation requires committed metadata.');
        $count = 0;
        $before = new \DateTimeImmutable('-1 day');
        foreach ($this->clients->internal()->getPaginator('ListObjectsV2', ['Bucket' => $this->config->bucketEducation]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $key = $object['Key'];
                if (!preg_match('~^(objects/[0-9a-f-]{36}|staging/[0-9a-f-]{36}/[0-9a-f]{32})$~D', $key)
                    || $object['LastModified'] >= $before || $this->referenced($key)) continue;
                $this->files->queue($key);
                if (++$count >= max(1, min(1000, $limit))) return $count;
            }
        }
        return $count;
    }

    private function referenced(string $key): bool
    {
        $uuid = str_starts_with($key, 'objects/') ? substr($key, 8) : null;
        $found = $this->db->createCommand('SELECT EXISTS(SELECT 1 FROM education.files WHERE storage_key=CAST(:uuid AS uuid))
            OR EXISTS(SELECT 1 FROM education.homework_files WHERE storage_key=CAST(:uuid AS uuid))
            OR EXISTS(SELECT 1 FROM education.file_transfers WHERE status=\'pending\' AND (staging_key=:key OR final_key=CAST(:uuid AS uuid)))',
            [':uuid' => $uuid, ':key' => $key])->queryScalar();
        return in_array($found, [true, 't', '1', 1], true);
    }
}
