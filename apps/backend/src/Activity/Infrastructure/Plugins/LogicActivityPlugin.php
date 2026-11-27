<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Plugins;

use App\Activity\Application\ActivityPayload;
use App\Activity\Application\ActivityPluginInterface;

final class LogicActivityPlugin implements ActivityPluginInterface
{
    public function code(): string
    {
        return 'platform-logic';
    }

    public function version(): int
    {
        return 2;
    }

    public function title(): string
    {
        return 'Logic & Patterns Lab';
    }

    public function category(): string
    {
        return 'logic';
    }

    public function capabilities(): array
    {
        return ['supportsDrafts', 'supportsAdaptive', 'supportsPractice'];
    }

    public function configSchema(): array
    {
        return [
            'rounds' => ['type' => 'integer', 'min' => 3, 'max' => 20, 'default' => 8],
            'difficulty' => ['type' => 'integer', 'min' => 1, 'max' => 3, 'default' => 1],
        ];
    }

    public function defaultConfig(): array
    {
        return ['rounds' => 8, 'difficulty' => 1];
    }

    public function safeParticipantConfigKeys(): array
    {
        return [];
    }

    public function generateExercise(array $config, int $seed, ?array $content = null): array
    {
        $roundsCount = max(3, min(20, (int) ($config['rounds'] ?? 8)));
        $difficulty = max(1, min(3, (int) ($config['difficulty'] ?? 1)));
        mt_srand($seed !== 0 ? $seed : 777);

        $rounds = [];
        $key = [];
        $explanations = [];

        for ($i = 0; $i < $roundsCount; $i++) {
            $id = 'r' . ($i + 1);
            $variant = $i % 3;
            if ($variant === 0) {
                $start = mt_rand(2, 12);
                $step = mt_rand(2, 4 + ($difficulty * 3));
                $seq = [$start, $start + $step, $start + 2 * $step, $start + 3 * $step];
                $ans = $start + 4 * $step;
                $opts = [$ans, $ans + $step, $ans - 1, $ans + 2];
                $prompt = 'Continue the sequence: ' . implode(', ', $seq) . ', ?';
                $explanation = "Each term adds +{$step}.";
            } elseif ($variant === 1) {
                $base = mt_rand(2, 4);
                $mult = $difficulty === 1 ? 2 : 3;
                $seq = [$base, $base * $mult, $base * $mult * $mult];
                $ans = $seq[2] * $mult;
                $opts = [$ans, $ans + $mult, $seq[2] + $mult, $ans * 2];
                $prompt = 'Find the next value: ' . implode(', ', $seq) . ', ?';
                $explanation = "Multiply previous value by {$mult}.";
            } else {
                $a = mt_rand(2, 9);
                $b = mt_rand(3, 8);
                $c = mt_rand(2, 6);
                $ans = $a + $b * $c;
                $opts = [$ans, ($a + $b) * $c, $ans + $c, $ans - $a];
                $prompt = "Compute following operator precedence: {$a} + {$b} * {$c}";
                $explanation = "Multiplication runs first: {$b} * {$c} = " . ($b * $c) . ", plus {$a} = {$ans}.";
            }
            $uniqueOpts = array_values(array_unique(array_map('strval', $opts)));
            sort($uniqueOpts);
            $rounds[] = [
                'id' => $id,
                'prompt' => $prompt,
                'options' => $uniqueOpts,
            ];
            $key[$id] = (string) $ans;
            $explanations[$id] = $explanation;
        }

        return [
            'difficulty' => $difficulty,
            'rounds' => $rounds,
            '_answerKey' => $key,
            '_explanations' => $explanations,
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
        $explanations = ActivityPayload::assoc($exercise['_explanations'] ?? null);
        $answers = is_array($submission['answers'] ?? null) ? ActivityPayload::assoc($submission['answers']) : $submission;

        $total = count($key);
        $correct = 0;
        $breakdown = [];
        foreach ($key as $id => $expected) {
            $actual = isset($answers[$id]) && is_scalar($answers[$id]) ? trim((string) $answers[$id]) : '';
            $expStr = is_scalar($expected) ? (string) $expected : '';
            $isCorrect = $actual === $expStr;
            if ($isCorrect) {
                $correct++;
            }
            $expNote = $explanations[$id] ?? '';
            $breakdown[] = [
                'itemId' => $id,
                'correct' => $isCorrect,
                'expected' => $expStr,
                'actual' => $actual,
                'explanation' => is_scalar($expNote) ? (string) $expNote : '',
            ];
        }

        $pct = $total > 0 ? round(($correct / $total) * 100.0, 2) : 0.0;
        return [
            'rawScore' => (float) $correct,
            'maxScore' => (float) $total,
            'percentage' => $pct,
            'passed' => $pct >= 65.0,
            'requiresManualReview' => false,
            'breakdown' => $breakdown,
            'metrics' => ['correct' => $correct, 'total' => $total, 'accuracy' => $pct],
            'telemetryVerified' => false,
            'feedback' => "Solved {$correct} of {$total} logic rounds ({$pct}%).",
        ];
    }
}
