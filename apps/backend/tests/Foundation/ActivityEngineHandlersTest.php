<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Activity\Infrastructure\Plugins\LogicActivityPlugin;
use App\Activity\Infrastructure\Plugins\TypingActivityPlugin;
use PHPUnit\Framework\TestCase;

final class ActivityEngineHandlersTest extends TestCase
{
    public function testCanonicalTypingPluginEvaluatesMetricsFromServerExercise(): void
    {
        $plugin = new TypingActivityPlugin();
        $exercise = $plugin->generateExercise(['timeLimitSeconds' => 15, 'language' => 'en', 'mode' => 'words', 'difficulty' => 1], 101);
        $prompt = (string) ($exercise['promptText'] ?? '');
        $this->assertNotSame('', $prompt);

        $typed = mb_substr($prompt, 0, 20, 'UTF-8');
        $eval = $plugin->evaluate(
            ['timeLimitSeconds' => 15, 'minAccuracy' => 80],
            $exercise,
            ['typedText' => $typed, 'elapsedSeconds' => 15],
        );

        $this->assertTrue($eval['passed']);
        $this->assertSame(20, $eval['metrics']['correctChars']);
        $this->assertSame(100.0, $eval['metrics']['accuracy']);
        $this->assertGreaterThan(0.0, $eval['metrics']['wpm']);
    }

    public function testCanonicalLogicPluginGeneratesLocalizedExerciseAndEvaluatesAnswers(): void
    {
        $plugin = new LogicActivityPlugin();
        $exercise = $plugin->generateExercise(['rounds' => 5, 'difficulty' => 2, 'language' => 'ru'], 42);
        $this->assertCount(5, $exercise['rounds']);
        $this->assertCount(5, $exercise['_answerKey']);
        $this->assertCount(5, $exercise['_explanations']);

        $eval = $plugin->evaluate(['rounds' => 5, 'minAccuracy' => 60], $exercise, ['answers' => $exercise['_answerKey']]);
        $this->assertTrue($eval['passed']);
        $this->assertSame(100.0, $eval['percentage']);
        $this->assertSame(5, $eval['metrics']['correctAnswers']);
        $this->assertSame(5, $eval['metrics']['totalRounds']);
    }
}
