<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Application\HomeworkActivityService;
use App\Education\Infrastructure\WorkspaceErasureHandler;

require_once __DIR__ . '/HomeworkFixture.php';

final class HomeworkPrivacyTest extends HomeworkFixture
{
    public function testTeacherWorkspaceErasureRemovesHomeworkBeforeOwnedGroups(): void
    {
        $assignment = $this->publish();
        $this->homework->write('save_draft', $this->learner['scope'], ['version' => 0, 'text' => 'Private answer'], $assignment['id']);
        $this->submit($assignment['id']);
        $person = $this->teacher->personId;
        $this->container->get(WorkspaceErasureHandler::class)->execute($person, $person, 'delete', $this->id());
        self::assertSame(0, $this->rowCount('homework_assignments', 'id', $assignment['id']));
        self::assertSame(0, $this->rowCount('groups', 'id', $this->group['id']));
    }

    public function testAuthorizedErasureRemovesOwnEvidenceAndPreservesPeerHomework(): void
    {
        $assignment = $this->publish(['type' => 'typing', 'activityConfig' => ['durationSeconds' => 15, 'language' => 'en']]);
        $activities = $this->container->get(HomeworkActivityService::class);
        $attempt = $activities->start($this->learner['scope'], $assignment['id']);
        $this->education->write('student_finish_attempt', $this->learner['scope'], ['typed' => 'sample', 'durationSeconds' => 15], $attempt['attempt']['id']);
        $this->submit($assignment['id']);
        $person = $this->learner['scope']->personId;
        $this->container->get(WorkspaceErasureHandler::class)->execute($person, $person, 'delete', $this->id());
        self::assertSame(0, $this->rowCount('homework_recipients', 'student_id', $this->learner['id']));
        self::assertSame(1, $this->rowCount('homework_recipients', 'student_id', $this->peer['id']));
        self::assertSame(0, $this->rowCount('activity_attempts', 'id', $attempt['attempt']['id']));
        self::assertSame($assignment['id'], $this->detail($assignment['id'], $this->peer['scope'])['assignment']['id']);
    }
}
