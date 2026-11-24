<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Education\Activity\ActivityPluginInterface;

final class MathSprintActivityHandler implements ActivityPluginInterface
{
    public function code(): string
    {
        return 'platform-math-sprint';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function title(): string
    {
        return 'Math Sprint';
    }

    public function description(): string
    {
        return 'Fast-paced mental math challenge for quick arithmetic calculations and streak building.';
    }

    public function configSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'durationSeconds' => ['type' => 'integer', 'enum' => [30, 60, 90, 120]],
                'difficulty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3],
                'operations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['add', 'sub', 'mul']],
                ],
                'targetScore' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 100],
            ],
            'required' => ['durationSeconds', 'difficulty'],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'durationSeconds' => 60,
            'difficulty' => 1,
            'operations' => ['add', 'sub'],
            'targetScore' => 20,
        ];
    }

    public function validateConfig(array $config): array
    {
        ActivityHandlerRegistry::only($config, ['durationSeconds', 'difficulty', 'operations', 'targetScore', 'language', 'maxAttempts']);
        $out = array_replace($this->defaultConfig(), $config);
        if (!in_array($out['durationSeconds'], [30, 60, 90, 120], true)) {
            throw new EducationError(422, 'invalid_input');
        }
        ActivityHandlerRegistry::integer($out['difficulty'], 1, 3);
        if (isset($out['operations']) && is_array($out['operations'])) {
            foreach ($out['operations'] as $op) {
                if (!in_array($op, ['add', 'sub', 'mul'], true)) {
                    throw new EducationError(422, 'invalid_input');
                }
            }
        }
        return $out;
    }

    public function createExercise(array $config, ?array $set = null): array
    {
        $difficulty = (int) ($config['difficulty'] ?? 1);
        $rawOps = $config['operations'] ?? null;
        /** @var list<string> $operations */
        $operations = is_array($rawOps) ? array_values(array_filter($rawOps, 'is_string')) : ['add', 'sub'];
        $durationSeconds = (int) ($config['durationSeconds'] ?? 60);
        $count = (int) ceil($durationSeconds * 0.8);
        $count = max(20, min(80, $count));

        $problems = [];
        $random = new \Random\Randomizer();

        for ($i = 1; $i <= $count; $i++) {
            $op = $operations[$random->getInt(0, count($operations) - 1)] ?? 'add';
            $max = $difficulty === 1 ? 20 : ($difficulty === 2 ? 50 : 100);

            if ($op === 'mul') {
                $a = $random->getInt(2, $difficulty === 1 ? 9 : 12);
                $b = $random->getInt(2, $difficulty === 1 ? 9 : 12);
                $problems[] = [
                    'id' => $i,
                    'prompt' => "{$a} × {$b}",
                    'answer' => $a * $b,
                ];
            } elseif ($op === 'sub') {
                $a = $random->getInt(10, $max);
                $b = $random->getInt(1, $a);
                $problems[] = [
                    'id' => $i,
                    'prompt' => "{$a} - {$b}",
                    'answer' => $a - $b,
                ];
            } else {
                $a = $random->getInt(1, (int) ($max / 2));
                $b = $random->getInt(1, (int) ($max / 2));
                $problems[] = [
                    'id' => $i,
                    'prompt' => "{$a} + {$b}",
                    'answer' => $a + $b,
                ];
            }
        }

        return [
            'problems' => $problems,
            'version' => 1,
            'code' => $this->code(),
        ];
    }

    public function publicExercise(array $attempt): array
    {
        $config = $this->config($attempt);
        $payload = ActivityHandlerRegistry::json($attempt['exercisePayload'] ?? []);
        /** @var list<array{id?: int|string, prompt?: string}> $problems */
        $problems = is_array($payload['problems'] ?? null) ? $payload['problems'] : [];
        $publicProblems = array_map(static fn(array $p): array => [
            'id' => (int) ($p['id'] ?? 0),
            'prompt' => $p['prompt'] ?? '',
        ], $problems);

        return [
            'problems' => $publicProblems,
            'durationSeconds' => (int) ($config['durationSeconds'] ?? 60),
            'difficulty' => (int) ($config['difficulty'] ?? 1),
            'version' => (int) ($payload['version'] ?? 1),
        ];
    }

    public function validateState(array $attempt, array $state): array
    {
        ActivityHandlerRegistry::only($state, ['currentProblemIndex', 'solvedCount', 'streak']);
        return $state;
    }

    public function evaluate(array $attempt, array $input): array
    {
        ActivityHandlerRegistry::only($input, ['durationSeconds', 'answers', 'completed']);
        $config = $this->config($attempt);
        $payload = ActivityHandlerRegistry::json($attempt['exercisePayload'] ?? []);
        /** @var list<array{id?: int|string, answer?: int|string}> $expected */
        $expected = is_array($payload['problems'] ?? null) ? $payload['problems'] : [];
        $expectedMap = [];
        foreach ($expected as $p) {
            $expectedMap[(int) ($p['id'] ?? 0)] = (int) ($p['answer'] ?? 0);
        }

        /** @var list<array{id?: int|string, value?: int|string}> $answers */
        $answers = is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $correct = 0;
        $incorrect = 0;
        $maxStreak = 0;
        $currentStreak = 0;

        foreach ($answers as $ans) {
            $id = (int) ($ans['id'] ?? 0);
            $val = (int) ($ans['value'] ?? 0);
            if (isset($expectedMap[$id])) {
                if ($expectedMap[$id] === $val) {
                    $correct++;
                    $currentStreak++;
                    if ($currentStreak > $maxStreak) {
                        $maxStreak = $currentStreak;
                    }
                } else {
                    $incorrect++;
                    $currentStreak = 0;
                }
            }
        }

        $total = $correct + $incorrect;
        $accuracy = $total > 0 ? (float) number_format(($correct / $total) * 100, 2, '.', '') : 0.0;
        $duration = max(1, (int) ($input['durationSeconds'] ?? $config['durationSeconds']));
        $ppm = (float) number_format(($correct / $duration) * 60, 1, '.', '');

        return [
            'fingerprint' => hash('sha256', json_encode($answers, JSON_THROW_ON_ERROR)),
            'metrics' => [
                'score' => $correct * 10 + $maxStreak * 5,
                'correctCount' => $correct,
                'incorrectCount' => $incorrect,
                'accuracy' => $accuracy,
                'maxStreak' => $maxStreak,
                'speedPpm' => $ppm,
                'durationSeconds' => $duration,
            ],
        ];
    }

    private function config(array $attempt): array
    {
        return ActivityHandlerRegistry::json($attempt['config'] ?? []);
    }
}
