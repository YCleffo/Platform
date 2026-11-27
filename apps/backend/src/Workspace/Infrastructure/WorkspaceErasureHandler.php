<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use App\Realtime\Application\RealtimeService;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Deletes subject-owned workspace preferences, operations, and trash records idempotently by item ID.
 */
final readonly class WorkspaceErasureHandler implements PersonalDataErasureHandler
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'workspace.erasure';
    }

    public function resourceType(): string
    {
        return 'workspace.account';
    }

    public function supportsAction(string $action): bool
    {
        return $action === 'delete';
    }

    public function owns(string $subjectPersonId, string $resourceId): bool
    {
        return $resourceId === $subjectPersonId
            && $this->db->createCommand(
                'SELECT 1 FROM core.persons WHERE id = CAST(:subject AS uuid)',
                [':subject' => $subjectPersonId],
            )->queryScalar() !== false;
    }

    public function reconcile(string $itemId): ?string
    {
        $result = $this->db->createCommand(
            'SELECT result FROM workspace.privacy_receipts WHERE item_id = CAST(:item AS uuid)',
            [':item' => $itemId],
        )->queryScalar();
        return is_string($result) ? $result : null;
    }

    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string
    {
        $subject = RealtimeService::assertUuid($subjectPersonId, 'subjectPersonId');
        $item = RealtimeService::assertUuid($itemId, 'itemId');
        if ($action !== 'delete' || strcasecmp($resourceId, $subject) !== 0) {
            throw new DomainException('Unsupported workspace erasure item.');
        }

        $existing = $this->reconcile($item);
        if ($existing !== null) {
            return $existing;
        }

        return $this->db->transaction(function () use ($subject, $item): string {
            $again = $this->reconcile($item);
            if ($again !== null) {
                return $again;
            }
            if (!$this->owns($subject, $subject)) {
                throw new DomainException('Workspace erasure subject unavailable.');
            }

            $this->db->createCommand(
                'DELETE FROM workspace.preferences
                 WHERE account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))',
                [':subject' => $subject],
            )->execute();

            $this->db->createCommand(
                'DELETE FROM workspace.operations
                 WHERE person_id = CAST(:subject AS uuid)
                    OR account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))',
                [':subject' => $subject],
            )->execute();

            $this->db->createCommand(
                'DELETE FROM workspace.trash_items
                 WHERE deleted_by_person_id = CAST(:subject AS uuid)
                    OR deleted_by_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))',
                [':subject' => $subject],
            )->execute();

            $this->db->createCommand(
                "INSERT INTO workspace.privacy_receipts(item_id, result)
                 VALUES (CAST(:item AS uuid), 'deleted')
                 ON CONFLICT (item_id) DO NOTHING",
                [':item' => $item],
            )->execute();

            return 'deleted';
        });
    }
}
