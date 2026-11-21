<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Application\Scope;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/ActivityEngineFixture.php';

final class ActivityEngineTest extends ActivityEngineFixture
{
    public function testLegacyTypingCalculatesUnicodeAndReplaysOnlyIdenticalEvidence(): void
    {
        $started = $this->startActivity(config: ['language' => 'ru']);
        self::assertSame('ru', $started['exercise']['language']);
        self::assertSame(15, $started['exercise']['durationSeconds']);
        $input = ['typed' => mb_substr($started['exercise']['text'], 0, 20), 'durationSeconds' => 15];
        $result = $this->education->write('student_finish_attempt', $this->learner['scope'], $input, $started['attempt']['id']);
        self::assertSame(20, $result['keystrokes']);
        self::assertSame(20, $result['correctChars']);
        self::assertEquals(16.0, $result['wpm']);
        self::assertEquals(100.0, $result['accuracy']);
        $this->sameResponse($result, $this->education->write('student_finish_attempt', $this->learner['scope'], $input, $started['attempt']['id']));
        $this->rejection(409, fn() => $this->education->write('student_finish_attempt', $this->learner['scope'], array_replace($input, ['typed' => 'changed']), $started['attempt']['id']));
        self::assertSame(1, $this->rowCount('typing_results', 'attempt_id', $started['attempt']['id']));
        self::assertSame(1, $this->rowCount('activity_results', 'attempt_id', $started['attempt']['id']));
        $history = $this->education->read('student_attempt_history', $this->learner['scope'], []);
        self::assertContains($started['attempt']['id'], array_column($history['items'], 'id'));
    }

    public static function invalidTypingSettings(): iterable
    {
        yield 'unsupported duration' => [['durationSeconds' => 16]];
        yield 'duration string' => [['durationSeconds' => '15']];
        yield 'language' => [['language' => 'xx']];
        yield 'difficulty zero' => [['difficulty' => 0]];
        yield 'difficulty high' => [['difficulty' => 4]];
        yield 'unknown mode' => [['mode' => 'free-xp']];
        yield 'negative target' => [['targetWpm' => -1]];
        yield 'invalid accuracy' => [['minAccuracy' => 101]];
        yield 'unbounded attempts' => [['maxAttempts' => 999]];
        yield 'injected score' => [['score' => 1000000]];
        yield 'substitute student' => [['studentId' => '019a0000-0000-7000-8000-000000000001']];
    }

    #[DataProvider('invalidTypingSettings')]
    public function testSettingsAreValidatedByServer(array $config): void
    {
        $this->rejection(422, fn() => $this->startActivity(config: $config));
    }

    public static function modes(): iterable
    {
        foreach (['words', 'sentences', 'numbers', 'code', 'accuracy'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('modes')]
    public function testVersionedTypingModesCreateActualExercises(string $mode): void
    {
        $started = $this->startActivity(config: ['mode' => $mode, 'difficulty' => 2]);
        self::assertSame($mode, $started['exercise']['mode']);
        self::assertSame(2, $started['exercise']['difficulty']);
        self::assertGreaterThan(0, $started['exercise']['version']);
        self::assertNotSame('', trim($started['exercise']['text']));
    }

    public function testLogicAnswersArePrivateUntilServerVerifiedFinishAndStateResumes(): void
    {
        $started = $this->startActivity('platform-logic');
        $id = $started['attempt']['id'];
        self::assertCount(5, $started['exercise']['rounds']);
        $this->assertNoSolutions($started);
        $answers = $this->correctLogicAnswers($id);
        $saved = $this->education->write('student_save_attempt_state', $this->learner['scope'], ['version' => 0, 'state' => ['answers' => [$answers[0]], 'currentRound' => 1]], $id);
        self::assertSame(1, $saved['attempt']['stateVersion']);
        $loaded = $this->education->read('student_attempt', $this->learner['scope'], [], $id);
        self::assertSame([$answers[0]], $loaded['attempt']['state']['answers']);
        self::assertSame($started['exercise'], $loaded['exercise']);
        $this->assertNoSolutions($loaded);
        $this->rejection(409, fn() => $this->education->write('student_save_attempt_state', $this->learner['scope'], ['version' => 0, 'state' => ['answers' => []]], $id));
        $result = $this->education->write('student_finish_attempt', $this->learner['scope'], ['answers' => $answers], $id);
        self::assertSame(5, $result['correctAnswers']);
        self::assertSame(5, $result['totalRounds']);
        self::assertSame(0, $result['errors']);
        self::assertEquals(100, $result['accuracy']);
        self::assertTrue($result['passed']);
        foreach ($result['rounds'] as $round) {
            self::assertTrue($round['correct']);
            self::assertNotEmpty($round['explanation']);
        }
        $this->sameResponse($result, $this->education->write('student_finish_attempt', $this->learner['scope'], ['answers' => $answers], $id));
        $changed = $answers;
        $changed[0]['optionId'] = $started['exercise']['rounds'][0]['options'][0]['id'] === $answers[0]['optionId']
            ? $started['exercise']['rounds'][0]['options'][1]['id'] : $started['exercise']['rounds'][0]['options'][0]['id'];
        $this->rejection(409, fn() => $this->education->write('student_finish_attempt', $this->learner['scope'], ['answers' => $changed], $id));
        self::assertSame(1, $this->rowCount('logic_results', 'attempt_id', $id));
    }

    public function testLogicRejectsClientScoresUnknownOptionsDuplicateAndUnknownRounds(): void
    {
        $started = $this->startActivity('platform-logic');
        $id = $started['attempt']['id'];
        $answers = $this->correctLogicAnswers($id);
        $bad = $answers;
        $bad[0]['optionId'] = 'nonexistent';
        $unknown = $answers;
        $unknown[0]['roundId'] = 'not-a-round';
        foreach ([['answers' => $answers, 'score' => 99999], ['answers' => $bad], ['answers' => $unknown], ['answers' => [...$answers, $answers[0]]], ['typed' => 'answer', 'durationSeconds' => 15]] as $input) {
            $this->rejection(422, fn() => $this->education->write('student_finish_attempt', $this->learner['scope'], $input, $id));
        }
        self::assertSame('running', $this->savedAttempt($id)['status']);
        self::assertSame(0, $this->rowCount('activity_results', 'attempt_id', $id));
        $wrong = array_map(static fn(array $round): array => ['roundId' => $round['id'], 'optionId' => $round['options'][0]['id']], $started['exercise']['rounds']);
        $result = $this->education->write('student_finish_attempt', $this->learner['scope'], ['answers' => $wrong], $id);
        self::assertSame(5 - $result['correctAnswers'], $result['errors']);
        self::assertEquals($result['correctAnswers'] / 5 * 100, $result['accuracy']);
    }

    public function testOwnershipUsesAccountOrganizationAndStudentAndRevocationIsImmediate(): void
    {
        $started = $this->startActivity();
        $id = $started['attempt']['id'];
        $own = $this->learner['scope'];
        $scopes = [$this->peer['scope'], new Scope($own->organizationId, $this->peer['accountId'], $own->personId, $own->locale, $own->timezone, $own->studentId),
            new Scope($this->outsider->organizationId, $own->accountId, $own->personId, $own->locale, $own->timezone, $own->studentId)];
        foreach ($scopes as $scope) {
            $this->rejection([403,404], fn() => $this->education->read('student_attempt', $scope, [], $id));
            $this->rejection([403,404], fn() => $this->education->write('student_finish_attempt', $scope, ['typed' => '', 'durationSeconds' => 15], $id));
            $this->rejection([403,404], fn() => $this->education->write('student_save_attempt_state', $scope, ['version' => 0, 'state' => ['typed' => 'stolen']], $id));
        }
        $this->education->write('revoke_student_account', $this->teacher, [], $this->learner['id']);
        $this->rejection([403,404], fn() => $this->education->write('student_finish_attempt', $own, ['typed' => '', 'durationSeconds' => 15], $id));
        $this->rejection([403,404], fn() => $this->startActivity(scope: $own));
    }

    public function testGroupAndIndividualAssignmentsAreScopedAndUseTheirImmutableConfig(): void
    {
        $group = $this->education->write('activities_assign', $this->teacher, ['groupId' => $this->group['id'], 'config' => ['durationSeconds' => 15, 'language' => 'en']], 'platform-typing');
        $individual = $this->education->write('activities_assign', $this->teacher, ['studentId' => $this->learner['id'], 'config' => ['difficulty' => 2, 'language' => 'ru', 'roundCount' => 5]], 'platform-logic');
        $typing = $this->startActivity(config: ['assignmentId' => $group['id']]);
        self::assertSame($group['id'], $typing['attempt']['assignmentId']);
        $logic = $this->startActivity('platform-logic', ['assignmentId' => $individual['id'], 'difficulty' => 2, 'language' => 'ru']);
        self::assertSame('ru', $logic['exercise']['language']);
        $this->rejection([403,404], fn() => $this->startActivity('platform-logic', ['assignmentId' => $individual['id'], 'difficulty' => 2, 'language' => 'ru'], $this->peer['scope']));
        $this->rejection(422, fn() => $this->startActivity(config: ['assignmentId' => $group['id'], 'durationSeconds' => 60]));
        $this->rejection([403,404], fn() => $this->education->write('activities_assign', $this->outsider, ['groupId' => $this->group['id'], 'config' => ['durationSeconds' => 15, 'language' => 'en']], 'platform-typing'));
        $this->rejection(422, fn() => $this->education->write('student_finish_attempt', $this->learner['scope'], ['typed' => '', 'durationSeconds' => 15, 'activityCode' => 'platform-logic'], $typing['attempt']['id']));
    }

    public function testStartedExerciseAndFinishedHistoryKeepTheirVersionAfterCatalogChanges(): void
    {
        $started = $this->startActivity();
        $id = $started['attempt']['id'];
        $this->db->createCommand("UPDATE education.activity_definitions SET contract_version=contract_version+1 WHERE code='platform-typing'")->execute();
        $this->db->createCommand("UPDATE education.activity_exercise_sets SET content=CAST(:content AS jsonb),version=version+100 WHERE activity_code='platform-typing'", [':content' => '["Newer catalog content"]'])->execute();
        $loaded = $this->education->read('student_attempt', $this->learner['scope'], [], $id);
        self::assertSame($started['exercise'], $loaded['exercise']);
        self::assertSame($started['attempt']['contractVersion'], $loaded['attempt']['contractVersion']);
        $input = ['typed' => mb_substr($started['exercise']['text'], 0, 25), 'durationSeconds' => 15];
        $result = $this->education->write('student_finish_attempt', $this->learner['scope'], $input, $id);
        self::assertSame(25, $result['correctChars']);
        $this->db->createCommand("UPDATE education.activity_definitions SET contract_version=contract_version+1 WHERE code='platform-typing'")->execute();
        $this->sameResponse($result, $this->education->write('student_finish_attempt', $this->learner['scope'], $input, $id));
        self::assertSame($started['exercise'], $this->education->read('student_attempt', $this->learner['scope'], [], $id)['exercise']);
    }

    public function testAttemptRevocationAndDisabledActivityApplyToExistingStateAndFinishes(): void
    {
        $started = $this->startActivity('platform-logic');
        $id = $started['attempt']['id'];
        $this->rejection([403,404], fn() => $this->education->write('revoke_activity_attempt', $this->outsider, [], $id));
        $this->education->write('revoke_activity_attempt', $this->teacher, [], $id);
        $this->rejection(409, fn() => $this->education->read('student_attempt', $this->learner['scope'], [], $id));
        $this->rejection(409, fn() => $this->education->write('student_finish_attempt', $this->learner['scope'], ['answers' => $this->correctLogicAnswers($id)], $id));
        $this->rejection(409, fn() => $this->education->write('student_save_attempt_state', $this->learner['scope'], ['version' => 0, 'state' => ['answers' => []]], $id));
        $typing = $this->startActivity();
        $this->db->createCommand("UPDATE education.activity_definitions SET enabled=false WHERE code='platform-typing'")->execute();
        $this->rejection([403,404], fn() => $this->education->write('student_finish_attempt', $this->learner['scope'], ['typed' => '', 'durationSeconds' => 15], $typing['attempt']['id']));
        $this->rejection([403,404], fn() => $this->startActivity());
    }
}
