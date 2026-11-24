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

    public function testDynamicPluginRegistrationAndSchemaRetrieval(): void
    {
        $registry = new ActivityHandlerRegistry();
        $this->assertTrue($registry->has('platform-assessment'));
        $this->assertTrue($registry->has('platform-typing'));
        $this->assertTrue($registry->has('platform-logic'));
        $this->assertTrue($registry->has('platform-math-sprint'));

        $assessmentHandler = $registry->handler('platform-assessment');
        $this->assertInstanceOf(\App\Education\Application\AssessmentActivityHandler::class, $assessmentHandler);

        $schema = $registry->schema('platform-assessment');
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('passingScorePercent', $schema['properties']);

        $defaultConfig = $registry->defaultConfig('platform-assessment');
        $this->assertSame(60, $defaultConfig['passingScorePercent']);

        // Custom dynamic plugin
        $customPlugin = new class implements ActivityPluginInterface {
            public function code(): string { return 'custom-plugin'; }
            public function version(): string { return '1.2.0'; }
            public function title(): string { return 'Custom Plugin'; }
            public function description(): string { return 'Custom description'; }
            public function configSchema(): array { return ['type' => 'object']; }
            public function defaultConfig(): array { return ['enabled' => true]; }
            public function validateConfig(array $config): array { return $config; }
            public function createExercise(array $config, ?array $set = null): array { return ['items' => []]; }
            public function publicExercise(array $attempt): array { return ['items' => []]; }
            public function validateState(array $attempt, array $state): array { return $state; }
            public function evaluate(array $attempt, array $input): array { return ['type' => 'custom', 'metrics' => ['score' => 100]]; }
        };

        $registry->register('custom-plugin', $customPlugin);
        $this->assertTrue($registry->has('custom-plugin'));
        $this->assertSame($customPlugin, $registry->handler('custom-plugin'));
        $plugins = $registry->plugins();
        $codes = array_column($plugins, 'code');
        $this->assertContains('custom-plugin', $codes);
    }

    public function testAssessmentActivityHandlerMaskingAndEvaluation(): void
    {
        $handler = new \App\Education\Application\AssessmentActivityHandler();
        $config = $handler->validateConfig(['passingScorePercent' => 75, 'timeLimitSeconds' => 600]);
        $this->assertSame(75, $config['passingScorePercent']);
        $this->assertSame(600, $config['timeLimitSeconds']);

        $exercise = $handler->createExercise($config);
        $this->assertNotEmpty($exercise['questions']);

        // Test public exercise masks answers
        $attempt = ['config' => $config, 'exercisePayload' => $exercise];
        $public = $handler->publicExercise($attempt);
        $this->assertNotEmpty($public['questions']);
        foreach ($public['questions'] as $q) {
            $this->assertArrayNotHasKey('answerKey', $q);
            $this->assertArrayNotHasKey('explanation', $q);
        }

        // Test evaluation with sample questions
        $answers = [
            'q1' => 'opt_stack', // correct single choice
            'q2' => ['opt_http', 'opt_dns'], // correct multi choice
            'q3' => true, // correct true/false
            'q4' => 1024, // correct numeric
            'q5' => 'git switch -c', // correct short text
        ];
        $evaluated = $handler->evaluate($attempt, ['answers' => $answers, 'elapsedSeconds' => 120]);
        $this->assertTrue($evaluated['metrics']['passed']);
        $this->assertEquals(100.0, $evaluated['metrics']['percentage']);
        $this->assertSame(7, $evaluated['metrics']['score']);

        // Test evaluation with incorrect answers
        $wrongAnswers = [
            'q1' => 'opt_queue',
            'q2' => ['opt_tcp'],
            'q3' => false,
            'q4' => 999,
            'q5' => 'unknown',
        ];
        $evaluatedWrong = $handler->evaluate($attempt, ['answers' => $wrongAnswers, 'elapsedSeconds' => 50]);
        $this->assertFalse($evaluatedWrong['metrics']['passed']);
        $this->assertEquals(0.0, $evaluatedWrong['metrics']['percentage']);
        $this->assertSame(0, $evaluatedWrong['metrics']['score']);
    }
}
