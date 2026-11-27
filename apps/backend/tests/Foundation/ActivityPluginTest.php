<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Activity\Application\ActivityPluginInterface;
use App\Activity\Application\ActivityPluginRegistry;
use App\Activity\Infrastructure\Plugins\AssessmentActivityPlugin;
use App\Activity\Infrastructure\Plugins\LogicActivityPlugin;
use App\Activity\Infrastructure\Plugins\MathSprintActivityPlugin;
use App\Activity\Infrastructure\Plugins\MemoryActivityPlugin;
use App\Activity\Infrastructure\Plugins\TypingActivityPlugin;
use App\Education\Application\ActivityCode;
use App\Education\Application\EducationError;
use PHPUnit\Framework\TestCase;

/** Tests canonical Universal Activity Engine plugins & Education config adapter validation. */
final class ActivityPluginTest extends TestCase
{
    private ActivityPluginRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ActivityPluginRegistry();
    }

    public function testCanonicalPluginsAreRegistered(): void
    {
        $codes = array_column($this->registry->describeAll(), 'code');
        foreach (['platform-typing', 'platform-logic', 'platform-math-sprint', 'platform-memory', 'platform-assessment'] as $code) {
            $this->assertTrue($this->registry->has($code));
            $this->assertInstanceOf(ActivityPluginInterface::class, $this->registry->get($code));
            $this->assertContains($code, $codes);
        }
    }

    public function testLegacyConfigAliasesNormalizeToCanonicalPluginSchemas(): void
    {
        $typing = ActivityCode::toCanonicalConfig($this->registry, 'kiberone-typing', [
            'durationSeconds' => 30,
            'language' => 'ru',
            'mode' => 'phrases',
            'difficulty' => 2,
        ]);
        $this->assertSame(30, $typing['timeLimitSeconds']);
        $this->assertSame('sentences', $typing['mode']);
        $this->assertSame('ru', $typing['language']);

        $logic = ActivityCode::toCanonicalConfig($this->registry, 'kiberone-logic', [
            'roundCount' => 10,
            'difficulty' => 3,
            'language' => 'ru',
        ]);
        $this->assertSame(10, $logic['rounds']);
        $this->assertSame(3, $logic['difficulty']);
        $this->assertSame('ru', $logic['language']);

        $this->expectException(EducationError::class);
        ActivityCode::toCanonicalConfig($this->registry, 'platform-math-sprint', ['durationSeconds' => 5]);
    }

    public function testMathSprintPluginDeterminismAndEvaluation(): void
    {
        $plugin = $this->registry->get('platform-math-sprint');
        $ex1 = $plugin->generateExercise(['questionCount' => 6, 'difficulty' => 2, 'operations' => ['add', 'mul']], 555);
        $ex2 = $plugin->generateExercise(['questionCount' => 6, 'difficulty' => 2, 'operations' => ['add', 'mul']], 555);

        $this->assertSame($ex1['items'], $ex2['items']);
        $this->assertCount(6, $ex1['items']);

        $answers = $ex1['_answerKey'];
        $eval = $plugin->evaluate(['questionCount' => 6], $ex1, ['answers' => $answers]);
        $this->assertTrue($eval['passed']);
        $this->assertSame(100.0, $eval['percentage']);
        $this->assertSame(6.0, (float) $eval['rawScore']);
    }

    public function testMemoryPluginGenerationAndEvaluation(): void
    {
        $plugin = $this->registry->get('platform-memory');
        $ex = $plugin->generateExercise(['rounds' => 4, 'gridSize' => 4, 'difficulty' => 1], 999);
        $this->assertArrayHasKey('rounds', $ex);
        $this->assertArrayHasKey('_answerKey', $ex);

        $eval = $plugin->evaluate(['rounds' => 4], $ex, ['answers' => $ex['_answerKey']]);
        $this->assertTrue($eval['passed']);
        $this->assertSame(100.0, $eval['percentage']);
    }

    public function testAssessmentPluginQuestionEvaluation(): void
    {
        $plugin = $this->registry->get('platform-assessment');
        $content = [
            'questions' => [
                [
                    'id' => 'q1',
                    'questionType' => 'single_choice',
                    'content' => ['prompt' => '2 + 2 = ?', 'options' => [['id' => 'a', 'label' => '3'], ['id' => 'b', 'label' => '4']]],
                    'answerKey' => 'b',
                    'explanation' => '4',
                    'defaultPoints' => 5,
                ],
                [
                    'id' => 'q2',
                    'questionType' => 'true_false',
                    'content' => ['prompt' => 'Sky is blue'],
                    'answerKey' => true,
                    'defaultPoints' => 5,
                ],
            ],
        ];
        $ex = $plugin->generateExercise(['shuffleQuestions' => false, 'shuffleOptions' => false, 'passingScorePercent' => 60], 1, $content);
        $this->assertCount(2, $ex['questions']);

        $eval = $plugin->evaluate(['passingScorePercent' => 60], $ex, ['answers' => ['q1' => 'b', 'q2' => true]], [], $content);
        $this->assertTrue($eval['passed']);
        $this->assertSame(10.0, (float) $eval['rawScore']);
        $this->assertSame(100.0, (float) $eval['percentage']);
    }
}
