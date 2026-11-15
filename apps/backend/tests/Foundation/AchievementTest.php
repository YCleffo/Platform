<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Application\AchievementService;

require_once __DIR__ . '/ActivityEngineFixture.php';

final class AchievementTest extends ActivityEngineFixture
{
    private AchievementService $achievements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->achievements = $this->container->get(AchievementService::class);
    }

    public function testSixRulesAreServerConfirmedReplaySafeAndReadScoped(): void
    {
        self::assertSame([], $this->activeCodes());
        $first = $this->finishTyping(25);
        self::assertContains('first-training', $this->activeCodes());
        self::assertContains('typing-accuracy', $this->activeCodes());
        self::assertNotContains('personal-best', $this->activeCodes(), 'A first result is a baseline, not an improvement.');
        $this->finishTyping(50);
        self::assertContains('personal-best', $this->activeCodes());
        $this->finishLogic();
        self::assertContains('first-logic', $this->activeCodes());
        self::assertContains('successful-streak', $this->activeCodes());

        $assignment = $this->publish();
        $this->answer($assignment['id']);
        $answer = $this->submit($assignment['id']);
        $submission = $answer['submission'];
        $this->homework->write('review', $this->teacher, ['version' => $submission['version'],
            'idempotencyKey' => $this->id(), 'decision' => 'accepted', 'resultStatus' => 'mastered'], $submission['id']);
        self::assertContains('first-homework', $this->activeCodes());
        self::assertCount(6, $this->activeCodes());
        $before = $this->unlockRows();
        $this->achievements->recordAttempt($this->learner['scope'], $first['attemptId']);
        $this->achievements->recordAttempt($this->learner['scope'], $first['attemptId']);
        $this->achievements->recordHomework($this->teacher, $submission['id']);
        self::assertSame($before, $this->unlockRows());
        self::assertSame(1, $this->rowCount('achievement_events', 'source_id', $first['attemptId']));
        $mine = $this->education->read('student_achievements', $this->learner['scope'], []);
        self::assertNotEmpty($mine);
        self::assertSame([], $this->activeCodes($this->peer['id']));
        $peer = $this->education->read('student_achievements', $this->peer['scope'], []);
        self::assertNotSame($mine, $peer, 'Another student must not receive the current learner unlocks.');
    }

    public function testUnfinishedAndForeignAttemptCannotCreateEvidenceOrAwards(): void
    {
        $started = $this->startActivity('platform-logic');
        $this->rejection(409, fn() => $this->achievements->recordAttempt($this->learner['scope'], $started['attempt']['id']));
        self::assertSame(0, $this->rowCount('achievement_events', 'source_id', $started['attempt']['id']));
        self::assertSame([], $this->activeCodes());
        $foreign = $this->finishTyping(scope: $this->peer['scope']);
        $this->rejection([403,404], fn() => $this->achievements->recordAttempt($this->learner['scope'], $foreign['attemptId']));
        self::assertSame([], $this->activeCodes());
        self::assertSame(0, (int) $this->db->createCommand('SELECT count(*) FROM education.achievement_events WHERE student_id=CAST(:student AS uuid) AND source_id=CAST(:source AS uuid)',
            [':student' => $this->learner['id'], ':source' => $foreign['attemptId']])->queryScalar());
    }

    public function testRuleVersionDoesNotRewritePreviousAwardAndNewVersionHasItsOwnIdentity(): void
    {
        $first = $this->finishTyping();
        $old = $this->unlockRows();
        $definition = $this->db->createCommand("INSERT INTO education.achievement_definitions(code,rule_version,name,description,rule,enabled) SELECT code,rule_version+100,name,description,rule,enabled FROM education.achievement_definitions WHERE code='first-training' AND rule_version=1 RETURNING id::text")->queryScalar();
        self::assertIsString($definition);
        self::assertSame($old, $this->unlockRows(), 'Changing a rule is not an implicit historical backfill.');
        $this->achievements->recordAttempt($this->learner['scope'], $first['attemptId']);
        $rows = $this->unlockRows();
        foreach ($old as $row) self::assertContains($row, $rows, 'A new rule version silently rewrote an old unlock.');
        self::assertSame(1, (int) $this->db->createCommand('SELECT count(*) FROM education.achievement_unlocks WHERE student_id=CAST(:student AS uuid) AND definition_id=CAST(:definition AS uuid)',
            [':student' => $this->learner['id'], ':definition' => $definition])->queryScalar());
        $this->achievements->recordAttempt($this->learner['scope'], $first['attemptId']);
        self::assertSame($rows, $this->unlockRows());
    }

    public function testRevokedSourceRetractsDependentAwardsButPreservesAuditAndRemainingEvidence(): void
    {
        $first = $this->finishTyping(25);
        self::assertContains('first-training', $this->activeCodes());
        $this->education->write('revoke_activity_attempt', $this->teacher, [], $first['attemptId']);
        self::assertSame([], $this->activeCodes());
        self::assertNotEmpty($this->unlockRows(), 'Revoked unlocks must retain the audit record.');
        foreach ($this->unlockRows() as $row) self::assertNotNull($row['revoked_at']);
        $this->achievements->recordAttempt($this->learner['scope'], $first['attemptId']);
        self::assertSame([], $this->activeCodes(), 'Replaying a revoked result restored an invalid award.');
        $this->finishTyping(30);
        self::assertContains('first-training', $this->activeCodes());
        self::assertContains('typing-accuracy', $this->activeCodes());
        self::assertNotContains('personal-best', $this->activeCodes(), 'A revoked baseline cannot be used for a new personal record.');
    }

    public function testConfirmedHomeworkCorrectionRetractsItsAwardAndReplayedOldDecisionCannotRestoreIt(): void
    {
        $assignment = $this->publish(); $this->answer($assignment['id']);
        $submission = $this->submit($assignment['id'])['submission'];
        $accepted = $this->homework->write('review', $this->teacher, ['version' => $submission['version'],
            'idempotencyKey' => $this->id(), 'decision' => 'accepted', 'resultStatus' => 'mastered'], $submission['id']);
        self::assertContains('first-homework', $this->activeCodes());
        // Homework's immutable one-review-per-version policy is preserved. A later module-authorized
        // source withdrawal must nevertheless retract awards, and cannot be undone by replaying an event.
        $this->rejection(409, fn() => $this->homework->write('review', $this->teacher, ['version' => $accepted['submission']['version'],
            'idempotencyKey' => $this->id(), 'decision' => 'returned', 'comment' => 'Corrected after review.'], $submission['id']));
        $this->db->createCommand("UPDATE education.homework_submissions SET state='RETURNED' WHERE id=CAST(:id AS uuid)",
            [':id' => $submission['id']])->execute();
        $this->achievements->recordHomework($this->teacher, $submission['id']);
        self::assertNotContains('first-homework', $this->activeCodes());
        $this->achievements->recordHomework($this->teacher, $submission['id']);
        self::assertNotContains('first-homework', $this->activeCodes());
    }

    public function testProgressRecalculatesFromConfirmedEvidenceAndPersonalBestIsComparable(): void
    {
        $this->finishTyping(25);
        $this->finishTyping(30, ['language' => 'ru']);
        self::assertNotContains('personal-best', $this->activeCodes(), 'Different languages are separate record categories.');
        $this->finishTyping(40, ['mode' => 'numbers']);
        self::assertNotContains('personal-best', $this->activeCodes(), 'Different exercise modes are separate record categories.');
        $this->finishTyping(25);
        self::assertNotContains('personal-best', $this->activeCodes(), 'Equal scores cannot improve a record.');
        $this->finishTyping(40);
        self::assertContains('personal-best', $this->activeCodes());
        $before = $this->education->read('student_activity_progress', $this->learner['scope'], []);
        $unfinished = $this->startActivity();
        $this->rejection(409, fn() => $this->achievements->recordAttempt($this->learner['scope'], $unfinished['attempt']['id']));
        self::assertSame($before, $this->education->read('student_activity_progress', $this->learner['scope'], []));
    }

    public function testDatabaseEnforcesSingleUnlockAndSingleSourceIdentity(): void
    {
        $first = $this->finishTyping();
        $constraints = $this->db->createCommand("SELECT tablename,indexdef FROM pg_indexes WHERE schemaname='education' AND tablename IN ('achievement_unlocks','achievement_events')")->queryAll();
        $unique = array_filter($constraints, static fn(array $row): bool => str_contains($row['indexdef'], 'UNIQUE'));
        self::assertNotEmpty(array_filter($unique, static fn(array $row): bool => $row['tablename'] === 'achievement_unlocks' && str_contains($row['indexdef'], '(organization_id, student_id, definition_id)')));
        self::assertNotEmpty(array_filter($unique, static fn(array $row): bool => $row['tablename'] === 'achievement_events' && str_contains($row['indexdef'], '(organization_id, student_id, source_type, source_id)')));
        for ($i = 0; $i < 5; $i++) $this->achievements->recordAttempt($this->learner['scope'], $first['attemptId']);
        self::assertSame(1, $this->rowCount('achievement_events', 'source_id', $first['attemptId']));
        self::assertCount(count(array_unique($this->activeCodes())), $this->activeCodes());
        // The HTTP smoke sends actual simultaneous finishes; these DB constraints are its race backstop.
    }

    private function activeCodes(?string $student = null): array
    {
        return $this->db->createCommand('SELECT d.code FROM education.achievement_unlocks u JOIN education.achievement_definitions d ON d.id=u.definition_id WHERE u.organization_id=CAST(:org AS uuid) AND u.student_id=CAST(:student AS uuid) AND u.revoked_at IS NULL ORDER BY d.code',
            [':org' => $this->teacher->organizationId, ':student' => $student ?? $this->learner['id']])->queryColumn();
    }

    private function unlockRows(): array
    {
        return $this->db->createCommand('SELECT id::text,definition_id::text,source_event_id::text,unlocked_at,revoked_at FROM education.achievement_unlocks WHERE student_id=CAST(:id AS uuid) ORDER BY id', [':id' => $this->learner['id']])->queryAll();
    }
}
