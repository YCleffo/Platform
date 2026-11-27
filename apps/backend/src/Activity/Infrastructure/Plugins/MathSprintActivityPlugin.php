<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Plugins;

use App\Activity\Application\ActivityPayload;
use App\Activity\Application\ActivityPluginInterface;

final class MathSprintActivityPlugin implements ActivityPluginInterface
{
    public function code(): string
    {
        return 'platform-math-sprint';
    }

    public function version(): int
    {
        return 2;
    }

    public function title(): string
    {
        return 'Math Sprint';
    }

    public function category(): string
    {
        return 'math';
    }

    public function capabilities(): array
    {
        return ['supportsDrafts', 'supportsTimer', 'supportsAdaptive', 'supportsPractice'];
    }

    public function configSchema(): array
    {
        return [
            'operations' => ['type' => 'multi_enum', 'values' => ['add', 'sub', 'mul', 'div'], 'default' => ['add', 'sub', 'mul']],
            'difficulty' => ['type' => 'integer', 'min' => 1, 'max' => 3, 'default' => 1],
            'questionCount' => ['type' => 'integer', 'min' => 5, 'max' => 50, 'default' => 15],
            'timeLimitSeconds' => ['type' => 'integer', 'min' => 20, 'max' => 600, 'default' => 90],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'operations' => ['add', 'sub', 'mul'],
            'difficulty' => 1,
            'questionCount' => 15,
            'timeLimitSeconds' => 90,
        ];
    }

    public function safeParticipantConfigKeys(): array
    {
        return [];
    }

    public function generateExercise(array $config, int $seed, ?array $content = null): array
    {
        $difficulty = max(1, min(3, (int) ($config['difficulty'] ?? 1)));
        $count = max(5, min(50, (int) ($config['questionCount'] ?? 15)));
        $opsList = ActivityPayload::stringList($config['operations'] ?? null);
        $ops = $opsList !== [] ? $opsList : ['add', 'sub', 'mul'];

        mt_srand($seed !== 0 ? $seed : 4242);
        $maxVal = match ($difficulty) {
            1 => 20,
            2 => 60,
            default => 150,
        };

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $op = $ops[$i % count($ops)];
            $a = mt_rand(2, $maxVal);
            $b = mt_rand(2, max(9, (int) floor($maxVal / 2)));
            [$prompt, $answer] = match ($op) {
                'sub' => (static function (int $x, int $y): array {
                    $hi = max($x, $y);
                    $lo = min($x, $y);
                    return ["{$hi} - {$lo}", $hi - $lo];
                })($a, $b),
                'mul' => (static function (int $d) use ($a, $b): array {
                    $m1 = ($a % ($d === 1 ? 12 : 25)) + 2;
                    $m2 = ($b % ($d === 1 ? 10 : 19)) + 2;
                    return ["{$m1} × {$m2}", $m1 * $m2];
                })($difficulty),
                'div' => (static function (int $d) use ($a, $b): array {
                    $divisor = ($b % ($d === 1 ? 9 : 15)) + 2;
                    $quotient = ($a % ($d === 1 ? 12 : 20)) + 2;
                    $dividend = $divisor * $quotient;
                    return ["{$dividend} ÷ {$divisor}", $quotient];
                })($difficulty),
                default => ["{$a} + {$b}", $a + $b],
            };
            $items[] = [
                'id' => 'm' . ($i + 1),
                'prompt' => $prompt,
                '_expected' => $answer,
            ];
        }

        return [
            'difficulty' => $difficulty,
            'timeLimitSeconds' => (int) ($config['timeLimitSeconds'] ?? 90),
            'items' => array_map(static fn(array $it): array => ['id' => $it['id'], 'prompt' => $it['prompt']], $items),
            '_answerKey' => array_column($items, '_expected', 'id'),
        ];
    }

    public function evaluate(
        array $config,
        array $exercise,
        array $submission,
        array $telemetry = [],
        ?array $content = null,
    ): array {
        $key = ActivityPayload::assoc($exercise['_answerKey'] ?? null);
        $answers = is_array($submission['answers'] ?? null) ? ActivityPayload::assoc($submission['answers']) : $submission;

        $total = count($key);
        $correct = 0;
        $breakdown = [];
        foreach ($key as $id => $expected) {
            $raw = $answers[$id] ?? null;
            $expNum = is_numeric($expected) ? (float) $expected : 0.0;
            $isCorrect = $raw !== null && is_numeric($raw) && abs((float) $raw - $expNum) < 0.001;
            if ($isCorrect) {
                $correct++;
            }
            $breakdown[] = [
                'itemId' => $id,
                'correct' => $isCorrect,
                'expected' => $expected,
                'actual' => $raw,
            ];
        }

        $pct = $total > 0 ? round(($correct / $total) * 100.0, 2) : 0.0;
        $elapsed = max(1.0, (float) ($submission['elapsedSeconds'] ?? $telemetry['elapsedSeconds'] ?? 30.0));
        $passed = $pct >= 70.0;

        return [
            'rawScore' => (float) $correct,
            'maxScore' => (float) $total,
            'percentage' => $pct,
            'passed' => $passed,
            'requiresManualReview' => false,
            'breakdown' => $breakdown,
            'metrics' => [
                'correct' => $correct,
                'total' => $total,
                'accuracy' => $pct,
                'elapsedSeconds' => round($elapsed, 2),
                'problemsPerMinute' => round(($total / $elapsed) * 60.0, 2),
            ],
            'telemetryVerified' => false,
            'feedback' => "Solved {$correct} of {$total} correctly ({$pct}%).",
        ];
    }
}
