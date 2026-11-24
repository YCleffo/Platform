<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Activity\ActivityPluginInterface;
use App\Education\Application\ActivityHandlerRegistry;
use App\Education\Application\EducationError;
use App\Education\Application\MathSprintActivityHandler;
use PHPUnit\Framework\TestCase;

final class ActivityPluginTest extends TestCase
{
    private ActivityHandlerRegistry $registry;
    private MathSprintActivityHandler $plugin;

    protected function setUp(): void
    {
        $this->registry = new ActivityHandlerRegistry();
        $this->plugin = new MathSprintActivityHandler();
    }

    public function testPluginImplementsInterfaceAndRegistersCorrectly(): void
    {
        $this->assertInstanceOf(ActivityPluginInterface::class, $this->plugin);
        $this->assertSame('platform-math-sprint', $this->plugin->code());
        $this->assertSame('1.0.0', $this->plugin->version());

        $handler = $this->registry->handler('platform-math-sprint');
        $this->assertInstanceOf(MathSprintActivityHandler::class, $handler);

        $plugins = $this->registry->plugins();
        $codes = array_column($plugins, 'code');
        $this->assertContains('platform-math-sprint', $codes);
    }

    public function testConfigValidation(): void
    {
        $valid = $this->plugin->validateConfig(['durationSeconds' => 60, 'difficulty' => 2, 'operations' => ['add', 'mul']]);
        $this->assertSame(60, $valid['durationSeconds']);
        $this->assertSame(2, $valid['difficulty']);
        $this->assertSame(['add', 'mul'], $valid['operations']);

        $this->expectException(EducationError::class);
        $this->plugin->validateConfig(['durationSeconds' => 45]); // invalid duration
    }

    public function testExerciseGenerationAndPublicMasking(): void
    {
        $config = $this->plugin->validateConfig(['durationSeconds' => 30, 'difficulty' => 1]);
        $exercise = $this->plugin->createExercise($config);

        $this->assertArrayHasKey('problems', $exercise);
        $this->assertNotEmpty($exercise['problems']);

        // Check private exercise has answers
        $first = $exercise['problems'][0];
        $this->assertArrayHasKey('prompt', $first);
        $this->assertArrayHasKey('answer', $first);

        // Check public exercise hides answers
        $attempt = [
            'config' => $config,
            'exercisePayload' => $exercise,
        ];
        $public = $this->plugin->publicExercise($attempt);
        $this->assertArrayHasKey('problems', $public);
        $firstPublic = $public['problems'][0];
        $this->assertArrayHasKey('prompt', $firstPublic);
        $this->assertArrayNotHasKey('answer', $firstPublic);
    }

    public function testDeterministicEvaluation(): void
    {
        $config = $this->plugin->validateConfig(['durationSeconds' => 30, 'difficulty' => 1]);
        $exercise = [
            'problems' => [
                ['id' => 1, 'prompt' => '2 + 3', 'answer' => 5],
                ['id' => 2, 'prompt' => '10 - 4', 'answer' => 6],
                ['id' => 3, 'prompt' => '3 * 3', 'answer' => 9],
            ],
            'version' => 1,
            'code' => 'platform-math-sprint',
        ];

        $attempt = [
            'config' => $config,
            'exercisePayload' => $exercise,
        ];

        // 2 correct, 1 incorrect
        $input = [
            'durationSeconds' => 30,
            'answers' => [
                ['id' => 1, 'value' => 5],
                ['id' => 2, 'value' => 6],
                ['id' => 3, 'value' => 99], // wrong
            ],
            'completed' => true,
        ];

        $result = $this->plugin->evaluate($attempt, $input);
        $this->assertArrayHasKey('metrics', $result);
        $metrics = $result['metrics'];

        $this->assertSame(2, $metrics['correctCount']);
        $this->assertSame(1, $metrics['incorrectCount']);
        $this->assertSame(2, $metrics['maxStreak']);
        $this->assertEqualsWithDelta(66.67, $metrics['accuracy'], 0.1);
        $this->assertSame(30, $metrics['score']); // 2*10 + 2*5
    }
}
