<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Application\Scope;

require_once __DIR__ . '/HomeworkFixture.php';

/** Independent QA fixtures exercise the production service and real guarded PostgreSQL. */
abstract class ActivityEngineFixture extends HomeworkFixture
{
    protected function startActivity(string $code = 'platform-typing', array $config = [], ?Scope $scope = null): array
    {
        $defaults = $code === 'platform-typing'
            ? ['language' => 'en', 'durationSeconds' => 15]
            : ['language' => 'en', 'difficulty' => 1, 'roundCount' => 5];
        return $this->education->write('student_start_attempt', $scope ?? $this->learner['scope'], array_replace($defaults, $config), $code);
    }

    protected function finishTyping(int $characters = 25, array $config = [], ?Scope $scope = null): array
    {
        $scope ??= $this->learner['scope'];
        $started = $this->startActivity(config: $config, scope: $scope);
        return $this->education->write('student_finish_attempt', $scope, [
            'typed' => mb_substr($started['exercise']['text'], 0, $characters, 'UTF-8'),
            'durationSeconds' => $started['exercise']['durationSeconds'],
        ], $started['attempt']['id']);
    }

    protected function savedAttempt(string $id): array
    {
        $row = $this->db->createCommand('SELECT * FROM education.activity_attempts WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryOne();
        self::assertIsArray($row);
        return $row;
    }

    /** Read the private server snapshot only in test code, never from a public DTO. */
    protected function correctLogicAnswers(string $id): array
    {
        $payload = json_decode($this->savedAttempt($id)['exercise_payload'], true, flags: JSON_THROW_ON_ERROR);
        $answers = [];
        foreach ($payload['rounds'] as $round) {
            $answers[] = ['roundId' => $round['id'], 'optionId' => $round['correctOptionId']];
        }
        return $answers;
    }

    protected function finishLogic(?Scope $scope = null): array
    {
        $scope ??= $this->learner['scope'];
        $started = $this->startActivity('platform-logic', scope: $scope);
        return $this->education->write('student_finish_attempt', $scope,
            ['answers' => $this->correctLogicAnswers($started['attempt']['id'])], $started['attempt']['id']);
    }

    protected function assertNoSolutions(array $dto): void
    {
        array_walk_recursive($dto, static function (mixed $value, string|int $key): void {
            self::assertNotContains($key, ['correctOptionId', 'explanation', 'exercise_private', 'correctAnswer', 'solution'], 'Public unfinished attempt leaks a solution.');
        });
    }
}
