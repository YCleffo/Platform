<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use Yiisoft\Db\Connection\ConnectionInterface;

/** Runs inside Core's authorized workspace erasure, before student-owned records. */
final readonly class FamilyPrivacy
{
    public function __construct(private ConnectionInterface $db) {}

    public function erase(string $subject): void
    {
        $students = 'SELECT id FROM education.students WHERE person_id=CAST(:subject AS uuid)
            OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))';
        $accounts = 'SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid)';
        $params = [':subject' => $subject];
        $this->db->createCommand('DELETE FROM education.notifications WHERE parent_student_id IN (' . $students . ')
            OR (parent_student_id IS NOT NULL AND person_id=CAST(:subject AS uuid))', $params)->execute();
        $this->db->createCommand('DELETE FROM education.parent_invites WHERE guardian_person_id=CAST(:subject AS uuid)
            OR student_id IN (' . $students . ') OR created_by_account_id IN (' . $accounts . ')', $params)->execute();
        $previous = $this->db->createCommand("SELECT current_setting('education.family_erasure',true)")->queryScalar();
        $this->db->createCommand("SELECT set_config('education.family_erasure','on',true)")->queryScalar();
        try {
            $this->db->createCommand('DELETE FROM education.family_history WHERE guardian_person_id=CAST(:subject AS uuid)
                OR student_id IN (' . $students . ') OR actor_account_id IN (' . $accounts . ')', $params)->execute();
        } finally {
            $this->db->createCommand(
                "SELECT set_config('education.family_erasure',:previous,true)",
                [':previous' => is_string($previous) ? $previous : 'off'],
            )->queryScalar();
        }
        $this->db->createCommand('DELETE FROM education.parent_accounts WHERE guardian_person_id=CAST(:subject AS uuid)
            OR account_id IN (' . $accounts . ')', $params)->execute();
        // The caller removes the subject's family edges. Guardian erasure never selects a child's learning records.
    }
}
