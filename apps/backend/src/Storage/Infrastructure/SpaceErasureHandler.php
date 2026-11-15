<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Metadata and binary deletion outbox commit together; bytes are removed after commit. */
final readonly class SpaceErasureHandler implements PersonalDataErasureHandler
{
    public function __construct(private ConnectionInterface $db, private RecoveryDependencyContributor $recovery) {}
    public function code(): string { return 'storage.space_erasure'; }
    public function resourceType(): string { return 'storage.space'; }
    public function supportsAction(string $action): bool { return $action === 'delete'; }
    public function reconcile(string $itemId): ?string
    {
        $value = $this->db->createCommand('SELECT result FROM storage.privacy_receipts WHERE item_id=CAST(:item AS uuid)', [':item' => $itemId])->queryScalar();
        return is_string($value) ? $value : null;
    }
    public function owns(string $subjectPersonId, string $resourceId): bool
    {
        $found = $this->db->createCommand('SELECT 1 FROM storage.spaces WHERE id=CAST(:id AS uuid) AND subject_person_id=CAST(:subject AS uuid) FOR UPDATE',
            [':id' => $resourceId, ':subject' => $subjectPersonId])->queryScalar();
        return $found !== false && $found !== null;
    }
    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string
    {
        if ($action !== 'delete') throw new DomainException('Unsupported Storage erasure action.');
        $receipt = $this->reconcile($itemId);
        if ($receipt !== null) return $receipt;
        return $this->db->transaction(function () use ($subjectPersonId, $resourceId, $itemId): string {
            if (!$this->owns($subjectPersonId, $resourceId)) throw new DomainException('Storage ownership changed.');
            if ($this->recovery->hasPendingRecovery($subjectPersonId)) throw new DomainException('Unfinished document recovery blocks erasure.');
            $params = [':space' => $resourceId];
            $this->db->createCommand('INSERT INTO storage.object_cleanup(object_key,multipart_upload_id,transfer_id,not_before)
                SELECT staging_key,multipart_upload_id,id,now()+interval \'16 minutes\' FROM storage.transfers
                WHERE space_id=CAST(:space AS uuid) AND direction=\'upload\' ON CONFLICT(object_key) DO NOTHING', $params)->execute();
            $this->db->createCommand('INSERT INTO storage.binary_cleanup(storage_key)
                SELECT final_storage_key FROM storage.transfers WHERE space_id=CAST(:space AS uuid) AND direction=\'upload\'
                ON CONFLICT(storage_key) DO NOTHING', $params)->execute();
            $this->db->createCommand('DELETE FROM storage.object_migrations WHERE module=\'storage\' AND storage_key IN
                (SELECT v.storage_key FROM storage.versions v JOIN storage.files f ON f.id=v.file_id
                    WHERE f.space_id=CAST(:space AS uuid))', $params)->execute();
            $this->db->createCommand('INSERT INTO storage.binary_cleanup(storage_key)
                SELECT v.storage_key FROM storage.versions v JOIN storage.files f ON f.id=v.file_id
                WHERE f.space_id=CAST(:space AS uuid) ON CONFLICT(storage_key) DO NOTHING', $params)->execute();
            $this->db->createCommand('DELETE FROM storage.conflicts WHERE space_id=CAST(:space AS uuid)', $params)->execute();
            $this->db->createCommand('DELETE FROM storage.manifests WHERE space_id=CAST(:space AS uuid)', $params)->execute();
            $this->db->createCommand('DELETE FROM storage.tickets WHERE space_id=CAST(:space AS uuid)', $params)->execute();
            $this->db->createCommand('DELETE FROM storage.versions WHERE file_id IN (SELECT id FROM storage.files WHERE space_id=CAST(:space AS uuid))', $params)->execute();
            $this->db->createCommand('DELETE FROM storage.files WHERE space_id=CAST(:space AS uuid)', $params)->execute();
            $this->db->createCommand('DELETE FROM storage.sync_sessions WHERE space_id=CAST(:space AS uuid)', $params)->execute();
            $this->db->createCommand('DELETE FROM storage.spaces WHERE id=CAST(:space AS uuid)', $params)->execute();
            $this->db->createCommand("INSERT INTO storage.privacy_receipts(item_id,result) VALUES(CAST(:item AS uuid),'deleted') ON CONFLICT(item_id) DO NOTHING",
                [':item' => $itemId])->execute();
            return 'deleted';
        });
    }
}
