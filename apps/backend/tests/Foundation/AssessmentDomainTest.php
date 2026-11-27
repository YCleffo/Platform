<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Activity\Infrastructure\Plugins\AssessmentActivityPlugin;
use App\Education\Application\AssessmentService;
use PHPUnit\Framework\TestCase;

/**
 * Tests canonical Assessment plugin validation, masking, evaluation across question types,
 * and strict canonical adapter contracts (without legacy fallback).
 */
final class AssessmentDomainTest extends TestCase
{
    private AssessmentActivityPlugin $plugin;

    protected function setUp(): void
    {
        $this->plugin = new AssessmentActivityPlugin();
    }

    public function testValidatesAllQuestionTypesAndRejectsMalformedDefinitions(): void
    {
        AssessmentActivityPlugin::validateQuestions([
            [
                'id' => 'q1',
                'type' => 'matching',
                'prompt' => 'Match pairs',
                'points' => 5,
                'pairs' => [
                    ['left' => 'A', 'right' => '1'],
                    ['left' => 'B', 'right' => '2'],
                ],
                'answerKey' => ['A' => '1', 'B' => '2'],
            ],
            [
                'id' => 'q2',
                'type' => 'numeric',
                'prompt' => 'Value of pi approx',
                'points' => 3,
                'tolerance' => 0.05,
                'answerKey' => 3.14,
            ],
            [
                'id' => 'q3',
                'type' => 'short_text',
                'prompt' => 'Capital of France',
                'points' => 2,
                'answerKey' => ['Paris', 'paris'],
            ],
        ]);
        $this->assertTrue(true);

        try {
            AssessmentActivityPlugin::validateQuestions([
                ['id' => 'q_bad', 'type' => 'matching', 'prompt' => 'Match pairs', 'points' => 5, 'pairs' => []],
            ]);
            $this->fail('Expected validation failure for insufficient matching pairs');
        } catch (\App\Shared\Application\HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('insufficient_matching_pairs', $e->reason);
        }
    }

    public function testEvaluatesAutoGradedAndManualReviewQuestionsDeterministically(): void
    {
        $content = [
            'questions' => [
                [
                    'id' => 'q_single',
                    'type' => 'single_choice',
                    'prompt' => 'Select 4',
                    'options' => [['id' => 'a', 'label' => '3'], ['id' => 'b', 'label' => '4']],
                    'answerKey' => 'b',
                    'points' => 2,
                ],
                [
                    'id' => 'q_multi',
                    'type' => 'multi_choice',
                    'prompt' => 'Select primes',
                    'options' => [['id' => 'a', 'label' => '2'], ['id' => 'b', 'label' => '3'], ['id' => 'c', 'label' => '4']],
                    'answerKey' => ['a', 'b'],
                    'points' => 4,
                ],
                [
                    'id' => 'q_essay',
                    'type' => 'long_text',
                    'prompt' => 'Explain your reasoning',
                    'answerKey' => 'rubric',
                    'points' => 4,
                ],
            ],
        ];

        $exercise = $this->plugin->generateExercise(['passingScorePercent' => 60], 123, $content);
        $this->assertArrayHasKey('questions', $exercise);
        $this->assertArrayNotHasKey('answerKey', $exercise['questions'][0]);

        $eval = $this->plugin->evaluate(
            ['passingScorePercent' => 60],
            $exercise,
            ['answers' => ['q_single' => 'b', 'q_multi' => ['a', 'b'], 'q_essay' => 'Detailed essay']],
            [],
            $content,
        );

        $this->assertSame(6.0, (float) $eval['rawScore']);
        $this->assertSame(10.0, (float) $eval['maxScore']);
        $this->assertTrue($eval['requiresManualReview']);
    }

    public function testAssessmentServiceRequiresActivityEngineAndIdentity(): void
    {
        $ref = new \ReflectionClass(AssessmentService::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('engine', $params[1]->getName());
        $this->assertFalse($params[1]->allowsNull());

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $param) {
                if ($param->getName() === 'identity') {
                    $this->assertFalse($param->allowsNull(), $method->getName() . '::$identity must be required.');
                }
            }
        }
    }

    public function testValidateQuestionDefinitionsRejectsUnknownTypeWith422(): void
    {
        try {
            AssessmentActivityPlugin::validateQuestions([
                ['id' => 'q1', 'type' => 'drag_and_drop', 'prompt' => 'Invalid', 'points' => 5],
            ]);
            $this->fail('Expected 422 invalid_question_type');
        } catch (\App\Shared\Application\HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('unsupported_question_type', $e->reason);
        }
    }
}
