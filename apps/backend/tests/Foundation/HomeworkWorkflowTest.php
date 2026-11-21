<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use DateTimeImmutable;

require_once __DIR__ . '/HomeworkFixture.php';

/** Targeted real PostgreSQL tests for the Homework state machine and tenant boundaries. */
final class HomeworkWorkflowTest extends HomeworkFixture
{
    public function testDraftRecipientEditsReplaceTargetsBeforePublication(): void
    {
        $draft = $this->draft(['groupId' => null, 'studentIds' => [$this->learner['id']]]);
        $detail = $this->homework->write(
            'update',
            $this->teacher,
            ['version' => $draft['version'], 'studentIds' => [$this->peer['id']]],
            $draft['id'],
        );
        self::assertSame([$this->peer['id']], $detail['assignment']['studentIds']);
        $this->homework->write('publish', $this->teacher, ['version' => $detail['assignment']['version']], $draft['id']);
        $this->rejection(404, fn() => $this->detail($draft['id']));
        self::assertSame('PUBLISHED', $this->detail($draft['id'], $this->peer['scope'])['assignment']['status']);
    }

    public function testPublicationFreezesUniqueRecipientsAndHistorySurvivesTransfer(): void
    {
        $otherGroup = $this->education->write('create_group', $this->teacher, ['name' => 'Other group']);
        $individual = $this->student('Individual', $this->teacher, $otherGroup['id']);
        $draft = $this->draft(['studentIds' => [$this->learner['id'], $this->learner['id'], $individual['id']]]);
        self::assertSame('DRAFT', $draft['status']);
        $this->rejection(404, fn() => $this->detail($draft['id']));
        $published = $this->homework->write('publish', $this->teacher, ['version' => $draft['version']], $draft['id'])['assignment'];
        self::assertSame('PUBLISHED', $published['status']);
        self::assertSame(3, $this->rowCount('homework_recipients', 'assignment_id', $published['id']));

        $later = $this->student('Later enrolment', $this->teacher, $this->group['id']);
        $this->rejection(404, fn() => $this->detail($published['id'], $later['scope']));
        $this->education->write('transfer_student', $this->teacher, ['groupId' => $otherGroup['id']], $this->group['id'], $this->learner['id']);
        self::assertSame($published['id'], $this->detail($published['id'])['assignment']['id']);
        self::assertSame(3, $this->rowCount('homework_recipients', 'assignment_id', $published['id']));

        $this->homework->write(
            'add_recipients',
            $this->teacher,
            ['version' => $published['version'], 'studentIds' => [$later['id'], $later['id'], $individual['id']]],
            $published['id'],
        );
        self::assertSame(4, $this->rowCount('homework_recipients', 'assignment_id', $published['id']));
        self::assertSame($published['id'], $this->detail($published['id'], $later['scope'])['assignment']['id']);
        self::assertGreaterThanOrEqual(3, $this->rowCount('homework_revisions', 'assignment_id', $published['id']));
        $rows = $this->homework->read('teacher_submissions', $this->teacher, [], $published['id'])['items'];
        self::assertCount(4, $rows);
        self::assertSame(['NOT_STARTED'], array_values(array_unique(array_column($rows, 'state'))));
    }

    public function testReturnResubmitAcceptPreservesImmutableAnswersAndAssignmentSnapshots(): void
    {
        $assignment = $this->publish();
        $this->answer($assignment['id'], 'Original answer');
        $this->submit($assignment['id']);
        $first = $this->detail($assignment['id'])['submission'];
        self::assertSame('SUBMITTED', $first['state']);
        self::assertCount(1, $first['versions']);
        self::assertSame('Original answer', $first['currentVersion']['text']);
        self::assertSame('Explain a loop', $first['currentVersion']['assignmentSnapshot']['title']);
        $this->rejection(409, fn() => $this->answer($assignment['id'], 'Silently overwritten answer'));

        $updated = $this->homework->write(
            'update',
            $this->teacher,
            ['version' => $assignment['version'], 'title' => 'Updated conditions', 'instructions' => 'Now show two examples.'],
            $assignment['id'],
        )['assignment'];
        $this->homework->write(
            'review',
            $this->teacher,
            ['version' => $first['version'], 'idempotencyKey' => $this->id(), 'decision' => 'returned', 'comment' => 'Show how it stops.'],
            $first['id'],
        );
        $returned = $this->detail($assignment['id'])['submission'];
        self::assertSame('RETURNED', $returned['state']);
        self::assertSame('Show how it stops.', $returned['reviews'][0]['comment']);
        $this->answer($assignment['id'], 'A condition stops the loop.');
        $this->submit($assignment['id']);
        $second = $this->detail($assignment['id'])['submission'];
        self::assertSame('RESUBMITTED', $second['state']);
        self::assertCount(2, $second['versions']);
        $versions = array_column($second['versions'], null, 'sequence');
        self::assertSame('Original answer', $versions[1]['text']);
        self::assertSame('Explain a loop', $versions[1]['assignmentSnapshot']['title']);
        self::assertSame('Updated conditions', $versions[2]['assignmentSnapshot']['title']);
        self::assertSame($updated['version'], $versions[2]['assignmentVersion']);

        $this->homework->write(
            'review',
            $this->teacher,
            ['version' => $second['version'], 'idempotencyKey' => $this->id(), 'decision' => 'accepted', 'resultStatus' => 'mastered', 'comment' => 'Good explanation.'],
            $second['id'],
        );
        $reviewed = $this->detail($assignment['id'])['submission'];
        self::assertSame('REVIEWED', $reviewed['state']);
        self::assertCount(2, $reviewed['reviews']);
        $this->rejection(409, fn() => $this->answer($assignment['id'], 'Changed after acceptance'));
        self::assertSame(2, $this->rowCount('homework_submission_versions', 'submission_id', $first['id']));
    }

    public function testInjectedServerClockRejectsLateSubmitWithoutDiscardingDraft(): void
    {
        $assignment = $this->publish(['dueAt' => '2026-11-13T13:00:00Z']);
        $this->answer($assignment['id'], 'Draft survives the deadline.');
        $this->clock->instant = new DateTimeImmutable('2026-11-13T13:00:01Z');
        $this->rejection(409, fn() => $this->submit($assignment['id']));
        $draft = $this->detail($assignment['id'])['submission'];
        self::assertSame('IN_PROGRESS', $draft['state']);
        self::assertSame('Draft survives the deadline.', $draft['text']);
        self::assertSame([], $draft['versions']);
        $this->homework->write('update', $this->teacher, ['version' => $assignment['version'], 'allowLate' => true], $assignment['id']);
        $this->submit($assignment['id']);
        $submitted = $this->detail($assignment['id'])['submission'];
        self::assertTrue($submitted['currentVersion']['late']);
        self::assertSame($this->clock->instant->getTimestamp(), (new DateTimeImmutable($submitted['currentVersion']['submittedAt']))->getTimestamp());
    }

    public function testIdempotencyReplaysExactResponsesWithoutDuplicateVersionsReviewsOrNotifications(): void
    {
        $assignment = $this->publish();
        $this->answer($assignment['id']);
        $draft = $this->detail($assignment['id'])['submission'];
        $input = ['version' => $draft['version'], 'idempotencyKey' => $this->id()];
        $first = $this->homework->write('submit', $this->learner['scope'], $input, $assignment['id']);
        $replay = $this->homework->write('submit', $this->learner['scope'], $input, $assignment['id']);
        $this->sameResponse($first, $replay);
        $submitted = $this->detail($assignment['id'])['submission'];
        self::assertSame(1, $this->rowCount('homework_submission_versions', 'submission_id', $submitted['id']));
        $review = ['version' => $submitted['version'], 'idempotencyKey' => $this->id(), 'decision' => 'accepted', 'resultStatus' => 'developing', 'comment' => 'Accepted.'];
        $firstReview = $this->homework->write('review', $this->teacher, $review, $submitted['id']);
        $this->sameResponse($firstReview, $this->homework->write('review', $this->teacher, $review, $submitted['id']));
        self::assertSame(1, $this->rowCount('homework_reviews', 'submission_id', $submitted['id']));
        $events = $this->db->createCommand(
            "SELECT person_id::text,kind,count(*)::int AS total FROM education.notifications WHERE organization_id=CAST(:org AS uuid) AND resource_id=CAST(:id AS uuid) GROUP BY person_id,kind",
            [':org' => $this->teacher->organizationId, ':id' => $assignment['id']],
        )->queryAll();
        self::assertNotEmpty($events);
        foreach ($events as $event) {
            self::assertSame(1, (int) $event['total'], 'A replay must not send the same event twice to one person.');
        }
    }

    public function testStaleAssignmentAndAnswerEditorsCannotOverwriteNewerChanges(): void
    {
        $draft = $this->draft();
        $this->homework->write('update', $this->teacher, ['version' => $draft['version'], 'title' => 'First editor saved'], $draft['id']);
        $this->rejection(409, fn() => $this->homework->write('update', $this->teacher, ['version' => $draft['version'], 'title' => 'Stale editor'], $draft['id']), 'version_conflict');
        $current = $this->homework->read('teacher_detail', $this->teacher, [], $draft['id'])['assignment'];
        self::assertSame('First editor saved', $current['title']);
        $this->homework->write('publish', $this->teacher, ['version' => $current['version']], $draft['id']);
        $before = $this->detail($draft['id'])['submission']['version'];
        $this->answer($draft['id'], 'First saved answer');
        $this->rejection(409, fn() => $this->homework->write('save_draft', $this->learner['scope'], ['version' => $before, 'text' => 'Stale answer'], $draft['id']), 'version_conflict');
        self::assertSame('First saved answer', $this->detail($draft['id'])['submission']['text']);
    }

    public function testLiveRolesTenantAndRecipientBoundariesRejectReusedScopesAndIds(): void
    {
        $assignment = $this->publish(['groupId' => null, 'studentIds' => [$this->learner['id']]]);
        $this->rejection(404, fn() => $this->detail($assignment['id'], $this->peer['scope']));
        $this->rejection([403, 404], fn() => $this->homework->read('teacher_detail', $this->colleague, [], $assignment['id']));
        $this->rejection(404, fn() => $this->homework->read('teacher_detail', $this->outsider, [], $assignment['id']));
        $this->rejection(404, fn() => $this->homework->write(
            'add_recipients',
            $this->outsider,
            ['version' => $assignment['version'], 'studentIds' => [$this->learner['id']]],
            $assignment['id'],
        ));
        $this->rejection(422, fn() => $this->homework->write(
            'save_draft',
            $this->learner['scope'],
            ['version' => 0, 'text' => 'Spoof', 'studentId' => $this->peer['id']],
            $assignment['id'],
        ));
        $this->answer($assignment['id']);
        $this->submit($assignment['id']);
        $submission = $this->detail($assignment['id'])['submission'];
        $this->rejection(404, fn() => $this->homework->read('submission_detail', $this->outsider, [], $submission['id']));
        $this->db->createCommand('DELETE FROM core.account_roles WHERE account_id=CAST(:id AS uuid)', [':id' => $this->teacher->accountId])->execute();
        $this->rejection(403, fn() => $this->homework->write(
            'review',
            $this->teacher,
            ['version' => $submission['version'], 'idempotencyKey' => $this->id(), 'decision' => 'accepted', 'resultStatus' => 'mastered', 'comment' => 'Revoked teacher'],
            $submission['id'],
        ));
        self::assertSame('SUBMITTED', $this->detail($assignment['id'])['submission']['state']);
        self::assertSame(0, $this->rowCount('homework_reviews', 'submission_id', $submission['id']));
    }

    public function testClosingStopsNewWorkAndArchivalPreservesSubmittedHistory(): void
    {
        $assignment = $this->publish();
        $this->answer($assignment['id']);
        $this->submit($assignment['id']);
        $this->answer($assignment['id'], 'Peer draft', $this->peer['scope']);
        $closed = $this->homework->write('close', $this->teacher, ['version' => $assignment['version']], $assignment['id'])['assignment'];
        self::assertSame('CLOSED', $closed['status']);
        $this->rejection(409, fn() => $this->submit($assignment['id'], $this->peer['scope']));
        $archived = $this->homework->write('archive', $this->teacher, ['version' => $closed['version']], $assignment['id'])['assignment'];
        self::assertSame('ARCHIVED', $archived['status']);
        $retained = $this->detail($assignment['id'])['submission'];
        self::assertSame('SUBMITTED', $retained['state']);
        self::assertCount(1, $retained['versions']);
        self::assertSame('Peer draft', $this->detail($assignment['id'], $this->peer['scope'])['submission']['text']);
    }
}
