<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure\Plugins;

use App\Activity\Application\ActivityPayload;
use App\Activity\Application\ActivityPluginInterface;

final class MemoryActivityPlugin implements ActivityPluginInterface
{
    public function code(): string
    {
        return 'platform-memory';
    }

    public function version(): int
    {
        return 2;
    }

    public function title(): string
    {
        return 'Memory & Flashcards Studio';
    }

    public function category(): string
    {
        return 'memory';
    }

    public function capabilities(): array
    {
        return ['supportsDrafts', 'supportsAdaptive', 'supportsPractice', 'supportsTimer'];
    }

    public function configSchema(): array
    {
        return [
            'mode' => ['type' => 'enum', 'values' => ['grid', 'sequence'], 'default' => 'grid'],
            'gridSize' => ['type' => 'integer', 'min' => 3, 'max' => 6, 'default' => 4],
            'rounds' => ['type' => 'integer', 'min' => 3, 'max' => 12, 'default' => 5],
            'difficulty' => ['type' => 'integer', 'min' => 1, 'max' => 3, 'default' => 1],
            'previewMs' => ['type' => 'integer', 'min' => 800, 'max' => 5000, 'default' => 1800],
        ];
    }

    public function defaultConfig(): array
    {
        return [
            'mode' => 'grid',
            'gridSize' => 4,
            'rounds' => 5,
            'difficulty' => 1,
            'previewMs' => 1800,
        ];
    }

    public function safeParticipantConfigKeys(): array
    {
        return [];
    }

    public function generateExercise(array $config, int $seed, ?array $content = null): array
    {
        $gridSize = max(3, min(6, (int) ($config['gridSize'] ?? 4)));
        $roundsCount = max(3, min(12, (int) ($config['rounds'] ?? 5)));
        $difficulty = max(1, min(3, (int) ($config['difficulty'] ?? 1)));
        $previewMs = max(800, min(5000, (int) ($config['previewMs'] ?? 1800)));

        mt_srand($seed !== 0 ? $seed : 9090);
        $totalCells = $gridSize * $gridSize;
        $rounds = [];
        $key = [];

        for ($r = 0; $r < $roundsCount; $r++) {
            $id = 'mem' . ($r + 1);
            $patternLen = min($totalCells - 1, 2 + $difficulty + (int) floor($r / 2));
            $indices = range(0, $totalCells - 1);
            shuffle($indices);
            $cells = array_slice($indices, 0, $patternLen);
            sort($cells);
            $rounds[] = [
                'id' => $id,
                'gridSize' => $gridSize,
                'previewMs' => $previewMs,
                'pattern' => $cells,
            ];
            $key[$id] = $cells;
        }

        return [
            'mode' => (string) ($config['mode'] ?? 'grid'),
            'gridSize' => $gridSize,
            'rounds' => $rounds,
            '_answerKey' => $key,
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
        $correctRounds = 0;
        $breakdown = [];
        foreach ($key as $id => $expectedCells) {
            $raw = ActivityPayload::intList($answers[$id] ?? null);
            $actual = array_values(array_unique($raw));
            sort($actual);
            $expected = ActivityPayload::intList($expectedCells);
            sort($expected);
            $isMatch = $actual === $expected;
            if ($isMatch) {
                $correctRounds++;
            }
            $breakdown[] = [
                'itemId' => $id,
                'correct' => $isMatch,
                'expectedCount' => count($expected),
                'selectedCount' => count($actual),
            ];
        }

        $pct = $total > 0 ? round(($correctRounds / $total) * 100.0, 2) : 0.0;
        return [
            'rawScore' => (float) $correctRounds,
            'maxScore' => (float) $total,
            'percentage' => $pct,
            'passed' => $pct >= 70.0,
            'requiresManualReview' => false,
            'breakdown' => $breakdown,
            'metrics' => ['correctRounds' => $correctRounds, 'totalRounds' => $total, 'accuracy' => $pct],
            'telemetryVerified' => false,
            'feedback' => "Recalled {$correctRounds} of {$total} patterns ({$pct}%).",
        ];
    }
}
