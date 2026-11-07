<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Core\Privacy\Application\PersonalDataErasureHandler;
use App\Education\Application\FileStore;
use App\Education\Application\Input;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Deletes one subject-owned Education workspace, including external file bytes.
 * The receipt is written last so a retry can prove completion by item ID.
 */
final readonly class WorkspaceErasureHandler implements PersonalDataErasureHandler
{
    public function __construct(
        private ConnectionInterface $db,
        private FileStore $files,
    ) {}

    public function code(): string
    {
        return 'education.workspace_erasure';
    }

    public function resourceType(): string
    {
        return 'education.workspace';
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
            'SELECT result FROM education.privacy_receipts WHERE item_id = CAST(:item AS uuid)',
            [':item' => $itemId],
        )->queryScalar();
        return is_string($result) ? $result : null;
    }

    public function execute(string $subjectPersonId, string $resourceId, string $action, string $itemId): string
    {
        $subject = Input::uuid($subjectPersonId);
        $item = Input::uuid($itemId);
        if ($action !== 'delete' || $resourceId !== $subjectPersonId) {
            throw new DomainException('Unsupported Education erasure item.');
        }
        $accounts = <<<'SQL'
            SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid)
            SQL;
        $keys = $this->db->createCommand(<<<'SQL'
            SELECT storage_key::text FROM education.files
            WHERE owner_person_id = CAST(:subject AS uuid)
                OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            UNION
            SELECT f.storage_key::text FROM education.files f
            JOIN education.profiles p ON p.avatar_file_id = f.id
            WHERE p.person_id = CAST(:subject AS uuid)
            SQL, [':subject' => $subject])->queryAll();

        $this->db->createCommand(
            'DELETE FROM education.profiles WHERE person_id = CAST(:subject AS uuid)'
            . ' OR account_id IN (' . $accounts . ')',
            [':subject' => $subject],
        )->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.attendance
            WHERE student_id IN (
                SELECT id FROM education.students
                WHERE person_id = CAST(:subject AS uuid)
                    OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            )
                OR lesson_id IN (
                    SELECT id FROM education.lessons
                    WHERE owner_person_id = CAST(:subject AS uuid)
                        OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                )
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.group_students
            WHERE student_id IN (
                SELECT id FROM education.students
                WHERE person_id = CAST(:subject AS uuid)
                    OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            )
                OR group_id IN (
                    SELECT id FROM education.groups
                    WHERE owner_person_id = CAST(:subject AS uuid)
                        OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                )
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.notifications
            WHERE message_id IN (
                SELECT id FROM education.messages WHERE sender_person_id = CAST(:subject AS uuid)
            )
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(
            'DELETE FROM education.notifications WHERE person_id = CAST(:subject AS uuid)',
            [':subject' => $subject],
        )->execute();
        $this->db->createCommand(<<<'SQL'
            UPDATE education.thread_members SET last_read_message_id = NULL
            WHERE last_read_message_id IN (
                SELECT id FROM education.messages WHERE sender_person_id = CAST(:subject AS uuid)
            )
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(
            'DELETE FROM education.messages WHERE sender_person_id = CAST(:subject AS uuid)',
            [':subject' => $subject],
        )->execute();
        $this->db->createCommand(
            'DELETE FROM education.thread_members WHERE person_id = CAST(:subject AS uuid)',
            [':subject' => $subject],
        )->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.notifications n
            WHERE n.thread_id IN (
                SELECT t.id FROM education.threads t
                WHERE NOT EXISTS (
                    SELECT 1 FROM education.thread_members tm WHERE tm.thread_id = t.id
                )
            )
            SQL)->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.files
            WHERE owner_person_id = CAST(:subject AS uuid)
                OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            UPDATE education.files SET lesson_id = NULL
            WHERE lesson_id IN (
                SELECT id FROM education.lessons
                WHERE owner_person_id = CAST(:subject AS uuid)
                    OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            )
                AND owner_person_id <> CAST(:subject AS uuid)
                AND owner_account_id NOT IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.result_revisions
            WHERE result_id IN (
                SELECT id FROM education.lesson_results
                WHERE student_id IN (
                    SELECT id FROM education.students
                    WHERE person_id = CAST(:subject AS uuid)
                        OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                )
                    OR lesson_id IN (
                        SELECT id FROM education.lessons
                        WHERE owner_person_id = CAST(:subject AS uuid)
                            OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                    )
            )
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.lesson_results
            WHERE student_id IN (
                SELECT id FROM education.students
                WHERE person_id = CAST(:subject AS uuid)
                    OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            )
                OR lesson_id IN (
                    SELECT id FROM education.lessons
                    WHERE owner_person_id = CAST(:subject AS uuid)
                        OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                )
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.lesson_reports
            WHERE owner_person_id = CAST(:subject AS uuid)
                OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                OR lesson_id IN (
                    SELECT id FROM education.lessons
                    WHERE owner_person_id = CAST(:subject AS uuid)
                        OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                )
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.lessons
            WHERE owner_person_id = CAST(:subject AS uuid)
                OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.groups
            WHERE owner_person_id = CAST(:subject AS uuid)
                OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.events
            WHERE owner_person_id = CAST(:subject AS uuid)
                OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.students
            WHERE person_id = CAST(:subject AS uuid)
                OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.teachers
            WHERE person_id = CAST(:subject AS uuid)
                OR account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
            SQL, [':subject' => $subject])->execute();
        $this->db->createCommand(<<<'SQL'
            DELETE FROM education.threads t
            WHERE NOT EXISTS (
                    SELECT 1 FROM education.thread_members tm WHERE tm.thread_id = t.id
                )
                AND NOT EXISTS (
                    SELECT 1 FROM education.messages m WHERE m.thread_id = t.id
                )
            SQL)->execute();

        foreach ($keys as $key) {
            $this->files->remove((string) $key['storage_key']);
        }

        $this->db->createCommand(<<<'SQL'
            INSERT INTO education.privacy_receipts (item_id, result)
            VALUES (CAST(:item AS uuid), 'deleted')
            ON CONFLICT (item_id) DO NOTHING
            SQL, [':item' => $item])->execute();
        return 'deleted';
    }
}
