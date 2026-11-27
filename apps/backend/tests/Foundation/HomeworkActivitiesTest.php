<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Application\HomeworkActivityService;
use App\Education\Application\HomeworkService;
use App\Education\Application\Scope;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/HomeworkFixture.php';

final class HomeworkActivitiesTest extends HomeworkFixture
{
    public function testFirstPolicyCanUseTheFirstAttemptThatMeetsTheAssignedTarget(): void
    {
        $assignment = $this->typing(['dueAt' => null,'attemptPolicy' => 'first',
            'activityConfig' => ['durationSeconds' => 15,'language' => 'en','minAccuracy' => 95]]);
        $start = $this->activities->start($this->learner['scope'], $assignment['id']);
        $failed = $this->education->write(
            'student_finish_attempt',
            $this->learner['scope'],
            ['typed' => str_repeat('!', 20),'durationSeconds' => 15],
            $start['attempt']['id'],
        );
        self::assertFalse($failed['passed']);
        $this->rejection(422, fn() => $this->submit($assignment['id']));
        $passed = $this->finish($assignment['id'], $this->learner['scope'], 25);
        $submitted = $this->submit($assignment['id']);
        self::assertSame($passed, $submitted['submission']['currentVersion']['activityAttemptId']);
    }

    public function testRussianTypingCountsCharactersAndRejectsChangingAFinishedAnswer(): void
    {
        $assignment = $this->typing(['activityConfig' => ['durationSeconds' => 15, 'language' => 'ru']]);
        $started = $this->activities->start($this->learner['scope'], $assignment['id']);
        $typed = mb_substr($started['exercise']['text'], 0, 20, 'UTF-8');
        $input = ['typed' => $typed, 'durationSeconds' => 15];
        $result = $this->education->write('student_finish_attempt', $this->learner['scope'], $input, $started['attempt']['id']);
        self::assertSame(20, $result['keystrokes']);
        self::assertEquals(16.0, $result['wpm']);
        self::assertEquals(100.0, $result['accuracy']);
        self::assertEquals($result, $this->education->write('student_finish_attempt', $this->learner['scope'], $input, $started['attempt']['id']));
        $this->rejection(409, fn() => $this->education->write(
            'student_finish_attempt',
            $this->learner['scope'],
            ['typed' => str_repeat('а', 20), 'durationSeconds' => 15],
            $started['attempt']['id'],
        ));
    }

    private HomeworkActivityService $activities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activities = $this->withClock(HomeworkActivityService::class, [HomeworkService::class => $this->homework]);
    }

    public function testAssignedTypingUsesServerConfigAndRejectsForeignOrUnassignedResults(): void
    {
        $assignment = $this->typing();
        $started = $this->activities->start($this->learner['scope'], $assignment['id']);
        self::assertSame(15, $started['exercise']['durationSeconds']);
        self::assertSame('en', $started['exercise']['language']);
        $bound = $this->db->createCommand('SELECT a.assignment_id::text,a.runtime_config,r.activity_assignment_id::text FROM activity.attempts a JOIN education.homework_recipients r ON r.activity_assignment_id=a.assignment_id WHERE a.id=CAST(:id AS uuid)', [':id' => $started['attempt']['id']])->queryOne();
        self::assertIsArray($bound);
        self::assertSame($bound['activity_assignment_id'], $bound['assignment_id']);
        $cfg = json_decode((string) $bound['runtime_config'], true);
        self::assertSame(15, $cfg['timeLimitSeconds'] ?? null);
        self::assertSame('en', $cfg['language'] ?? null);
        $this->rejection(422, fn() => $this->education->write(
            'student_start_attempt',
            $this->learner['scope'],
            ['assignmentId' => $bound['assignment_id'], 'durationSeconds' => 60, 'language' => 'ru'],
            'platform-typing',
        ));
        $this->education->write('student_finish_attempt', $this->learner['scope'], ['typed' => $started['exercise']['text'], 'durationSeconds' => 15], $started['attempt']['id']);
        self::assertSame($started['attempt']['id'], $this->detail($assignment['id'])['submission']['selectedActivityAttemptId']);

        $peerAttempt = $this->finish($assignment['id'], $this->peer['scope'], 20);
        $this->rejection(404, fn() => $this->activities->attach($this->learner['scope'], $assignment['id'], $peerAttempt));
        $ordinary = $this->education->write('student_start_attempt', $this->learner['scope'], ['durationSeconds' => 15, 'language' => 'en'], 'platform-typing');
        $this->education->write('student_finish_attempt', $this->learner['scope'], ['typed' => $ordinary['exercise']['text'], 'durationSeconds' => 15], $ordinary['attempt']['id']);
        $this->rejection(404, fn() => $this->activities->attach($this->learner['scope'], $assignment['id'], $ordinary['attempt']['id']));
        $this->rejection(422, fn() => $this->homework->write(
            'save_draft',
            $this->learner['scope'],
            ['version' => $this->detail($assignment['id'])['submission']['version'], 'text' => '', 'wpm' => 999, 'accuracy' => 100],
            $assignment['id'],
        ));
    }

    public static function policies(): iterable
    {
        yield 'first result' => ['first', 0];
        yield 'latest result' => ['latest', 2];
        yield 'best verified result' => ['best', 1];
    }

    #[DataProvider('policies')]
    public function testAttemptPolicySelectsVerifiedEvidenceAndReturnedWorkRequiresANewAttempt(string $policy, int $expected): void
    {
        $assignment = $this->typing(['attemptPolicy' => $policy, 'maxAttempts' => 3, 'dueAt' => null]);
        $attempts = [$this->finish($assignment['id'], $this->learner['scope'], 10),
            $this->finish($assignment['id'], $this->learner['scope']), $this->finish($assignment['id'], $this->learner['scope'], 20)];
        $this->rejection(409, fn() => $this->activities->start($this->learner['scope'], $assignment['id']), 'attempt_limit_reached');
        $this->clock->instant = new \DateTimeImmutable();
        $this->submit($assignment['id']);
        $first = $this->detail($assignment['id'])['submission'];
        self::assertSame($attempts[$expected], $first['currentVersion']['activityAttemptId']);
        self::assertCount(1, $first['versions']);
        $this->clock->instant = new \DateTimeImmutable();
        $this->homework->write(
            'review',
            $this->teacher,
            ['version' => $first['version'], 'idempotencyKey' => $this->id(), 'decision' => 'returned', 'comment' => 'Please try once more.'],
            $first['id'],
        );
        // Replayed attachment is harmless, but already consumed evidence cannot create another answer version.
        $this->activities->attach($this->learner['scope'], $assignment['id'], $attempts[$expected]);
        // An unused attempt finished before the return is old work, not a new revision.
        $this->rejection([409,422], fn() => $this->submit($assignment['id']));
        self::assertSame(1, $this->rowCount('homework_submission_versions', 'submission_id', $first['id']));
        $this->homework->write('update', $this->teacher, ['version' => $assignment['version'], 'maxAttempts' => 4], $assignment['id']);
        $fresh = $this->finish($assignment['id'], $this->learner['scope'], 25);
        $this->clock->instant = new \DateTimeImmutable();
        $this->submit($assignment['id']);
        $resubmitted = $this->detail($assignment['id'])['submission'];
        self::assertSame('RESUBMITTED', $resubmitted['state']);
        self::assertSame($fresh, $resubmitted['currentVersion']['activityAttemptId']);
        self::assertCount(2, $resubmitted['versions']);
    }

    private function typing(array $overrides = []): array
    {
        return $this->publish(array_replace(['type' => 'typing', 'activityConfig' => ['durationSeconds' => 15, 'language' => 'en'], 'maxAttempts' => 3], $overrides));
    }

    private function finish(string $assignment, Scope $scope, ?int $characters = null): string
    {
        $started = $this->activities->start($scope, $assignment);
        $typed = $characters === null ? $started['exercise']['text'] : substr($started['exercise']['text'], 0, $characters);
        $this->education->write('student_finish_attempt', $scope, ['typed' => $typed, 'durationSeconds' => 15], $started['attempt']['id']);
        return $started['attempt']['id'];
    }
}
