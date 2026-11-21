<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use Yiisoft\Db\Connection\ConnectionInterface;

/** Invoked only by Core's authorized workspace erasure transaction. */
final readonly class HomeworkPrivacy
{
    public function __construct(private ConnectionInterface $db) {}

    /** @return list<array{storage_key:string}> */
    public function erase(string $subject): array
    {
        $params = [':subject' => $subject];
        $assignments = 'SELECT h.id FROM education.homework_assignments h WHERE h.author_person_id=CAST(:subject AS uuid)
            OR h.group_id IN (SELECT id FROM education.groups WHERE owner_person_id=CAST(:subject AS uuid))
            OR h.lesson_id IN (SELECT id FROM education.lessons WHERE owner_person_id=CAST(:subject AS uuid))';
        $students = 'SELECT id FROM education.students WHERE person_id=CAST(:subject AS uuid)
            OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))';
        $recipients = 'SELECT r.id FROM education.homework_recipients r WHERE r.student_id IN (' . $students . ') OR r.assignment_id IN (' . $assignments . ')';
        $submissions = 'SELECT id FROM education.homework_submissions WHERE recipient_id IN (' . $recipients . ')';
        $versions = 'SELECT id FROM education.homework_submission_versions WHERE submission_id IN (' . $submissions . ')';
        $keys = $this->db->createCommand('SELECT storage_key::text FROM education.homework_files WHERE recipient_id IN (' . $recipients . ')
            OR assignment_id IN (' . $assignments . ')', $params)->queryAll();
        $activityIds = $this->db->createCommand('SELECT activity_assignment_id::text FROM education.homework_recipients
            WHERE id IN (' . $recipients . ') AND activity_assignment_id IS NOT NULL', $params)->queryColumn();
        $this->db->createCommand("SELECT set_config('education.homework_erasure','on',true)")->queryScalar();
        foreach ([
            'DELETE FROM education.homework_idempotency WHERE actor_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))
                OR resource_id IN (' . $assignments . ') OR resource_id IN (' . $submissions . ')',
            'DELETE FROM education.homework_version_files WHERE version_id IN (' . $versions . ')',
            'DELETE FROM education.homework_draft_files WHERE recipient_id IN (' . $recipients . ')',
            'DELETE FROM education.homework_files WHERE recipient_id IN (' . $recipients . ') OR assignment_id IN (' . $assignments . ')',
            'DELETE FROM education.homework_reviews WHERE submission_id IN (' . $submissions . ')',
            'DELETE FROM education.homework_submission_versions WHERE submission_id IN (' . $submissions . ')',
            'DELETE FROM education.homework_attempt_links WHERE recipient_id IN (' . $recipients . ')',
            'DELETE FROM education.homework_submissions WHERE recipient_id IN (' . $recipients . ')',
            'DELETE FROM education.homework_recipients WHERE id IN (' . $recipients . ')',
            'DELETE FROM education.notifications WHERE resource_id IN (' . $assignments . ')',
            'DELETE FROM education.homework_revisions WHERE assignment_id IN (' . $assignments . ')',
            'DELETE FROM education.homework_assignments WHERE id IN (' . $assignments . ')',
        ] as $sql) {
            $this->db->createCommand($sql, $params)->execute();
        }
        $this->db->createCommand("SELECT set_config('education.homework_erasure','off',true)")->queryScalar();
        foreach ($activityIds as $activity) {
            $this->db->createCommand('DELETE FROM education.typing_results WHERE attempt_id IN
                (SELECT id FROM education.activity_attempts WHERE assignment_id=CAST(:id AS uuid))', [':id' => $activity])->execute();
            $this->db->createCommand('DELETE FROM education.activity_attempts WHERE assignment_id=CAST(:id AS uuid)', [':id' => $activity])->execute();
            $this->db->createCommand('DELETE FROM education.activity_assignments WHERE id=CAST(:id AS uuid)', [':id' => $activity])->execute();
        }
        $activities = 'SELECT id FROM education.activity_assignments WHERE student_id IN (' . $students . ')
            OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))
            OR group_id IN (SELECT id FROM education.groups WHERE owner_person_id=CAST(:subject AS uuid))';
        foreach ([
            'DELETE FROM education.typing_results WHERE student_id IN (' . $students . ') OR attempt_id IN
                (SELECT id FROM education.activity_attempts WHERE assignment_id IN (' . $activities . '))',
            'DELETE FROM education.activity_attempts WHERE student_id IN (' . $students . ') OR assignment_id IN (' . $activities . ')',
            'DELETE FROM education.activity_assignments WHERE id IN (' . $activities . ')',
            'DELETE FROM education.student_invites WHERE student_id IN (' . $students . ')',
            'DELETE FROM education.student_accounts WHERE student_id IN (' . $students . ')',
        ] as $sql) {
            $this->db->createCommand($sql, $params)->execute();
        }
        return $keys;
    }
}
