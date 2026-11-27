<?php

declare(strict_types=1);

namespace App\Workspace\Infrastructure;

use App\Core\Privacy\Application\PagedPersonalDataContributor;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataSection;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class WorkspacePrivacyContributor implements PagedPersonalDataContributor
{
    public function __construct(private ConnectionInterface $db) {}

    public function code(): string
    {
        return 'workspace.export';
    }

    public function categories(): array
    {
        return ['workspace'];
    }

    public function collect(PersonalDataExportContext $context): PersonalDataSection
    {
        $records = [];
        foreach ($this->pages($context) as $page) {
            foreach ($page->records as $record) {
                $records[] = $record;
            }
        }
        return new PersonalDataSection($this->code(), $this->categories(), $records);
    }

    public function pages(PersonalDataExportContext $context): iterable
    {
        $queries = [
            'preference' => <<<'SQL'
                SELECT p.id::text, p.account_id::text AS "accountId", p.organization_id::text AS "organizationId",
                       p.key, p.value::text AS "valueJson", p.updated_at::text AS "updatedAt"
                FROM workspace.preferences p
                WHERE p.account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                SQL,
            'operation' => <<<'SQL'
                SELECT o.id::text, o.organization_id::text AS "organizationId", o.account_id::text AS "accountId",
                       o.action_key AS "actionKey", o.entity_type AS "entityType", o.entity_id::text AS "entityId",
                       o.mode, o.status, o.revision, o.created_at::text AS "createdAt", o.finalized_at::text AS "finalizedAt"
                FROM workspace.operations o
                WHERE o.person_id = CAST(:subject AS uuid)
                   OR o.account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                SQL,
            'trash_item' => <<<'SQL'
                SELECT t.id::text, t.organization_id::text AS "organizationId", t.entity_type AS "entityType",
                       t.entity_id::text AS "entityId", t.module_code AS "moduleCode", t.title,
                       t.restore_status AS "restoreStatus", t.deleted_at::text AS "deletedAt",
                       t.restored_at::text AS "restoredAt", t.purged_at::text AS "purgedAt"
                FROM workspace.trash_items t
                WHERE t.deleted_by_person_id = CAST(:subject AS uuid)
                   OR t.deleted_by_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                SQL,
        ];

        $anyYielded = false;
        foreach ($queries as $type => $sql) {
            $offset = 0;
            do {
                $rows = $this->db->createCommand(
                    $sql . "\nORDER BY 1 LIMIT 200 OFFSET :offset",
                    [':subject' => $context->subjectPersonId, ':offset' => $offset],
                )->queryAll();
                if ($rows === []) {
                    break;
                }
                $records = [];
                /** @var array<string, mixed> $row */
                foreach ($rows as $row) {
                    if (isset($row['valueJson']) && is_string($row['valueJson']) && strlen($row['valueJson']) > 2048) {
                        $row['valueJson'] = substr($row['valueJson'], 0, 2048);
                    }
                    $records[] = ['type' => $type, ...$row];
                }
                $anyYielded = true;
                yield new PersonalDataSection($this->code(), $this->categories(), $records);
                $offset += count($rows);
            } while (count($rows) === 200);
        }

        if (!$anyYielded) {
            yield new PersonalDataSection($this->code(), $this->categories(), []);
        }
    }
}
