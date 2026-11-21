<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use Yiisoft\Db\Connection\ConnectionInterface;

/** Runs before HomeworkPrivacy and attempt deletion in authorized Core erasure. */
final readonly class ActivityPrivacy
{
    public function __construct(private ConnectionInterface $db) {}
    public function erase(string $subject): void
    {
        $students = 'SELECT id FROM education.students WHERE person_id=CAST(:subject AS uuid)
            OR owner_account_id IN(SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))';
        $homework = 'SELECT h.id FROM education.homework_assignments h WHERE h.author_person_id=CAST(:subject AS uuid)
            OR h.group_id IN(SELECT id FROM education.groups WHERE owner_person_id=CAST(:subject AS uuid))
            OR h.lesson_id IN(SELECT id FROM education.lessons WHERE owner_person_id=CAST(:subject AS uuid))';
        $recipients = 'SELECT r.id FROM education.homework_recipients r WHERE r.student_id IN(' . $students . ') OR r.assignment_id IN(' . $homework . ')';
        $activityAssignments = 'SELECT id FROM education.activity_assignments WHERE student_id IN(' . $students . ')
            OR owner_account_id IN(SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))
            OR group_id IN(SELECT id FROM education.groups WHERE owner_person_id=CAST(:subject AS uuid))
            OR id IN(SELECT activity_assignment_id FROM education.homework_recipients WHERE id IN(' . $recipients . '))';
        $attempts = 'SELECT a.id FROM education.activity_attempts a WHERE a.student_id IN(' . $students . ')
            OR a.account_id IN(SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))
            OR a.assignment_id IN(' . $activityAssignments . ')';
        $submissions = 'SELECT id FROM education.homework_submissions WHERE recipient_id IN(' . $recipients . ')';
        $events = 'SELECT e.id FROM education.achievement_events e WHERE e.student_id IN(' . $students . ')
            OR (e.source_type=\'attempt\' AND e.source_id IN(' . $attempts . '))
            OR (e.source_type=\'homework\' AND e.source_id IN(' . $submissions . '))';
        $params = [':subject' => $subject];
        $this->db->createCommand('DELETE FROM education.achievement_unlocks WHERE student_id IN(' . $students . ') OR source_event_id IN(' . $events . ')', $params)->execute();
        $this->db->createCommand('DELETE FROM education.achievement_events WHERE id IN(' . $events . ')', $params)->execute();
        $this->db->createCommand('DELETE FROM education.logic_results WHERE attempt_id IN(' . $attempts . ')', $params)->execute();
        $this->db->createCommand('DELETE FROM education.activity_results WHERE attempt_id IN(' . $attempts . ')', $params)->execute();
    }
}
