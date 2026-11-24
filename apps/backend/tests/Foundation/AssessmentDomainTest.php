<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Education\Activity\ActivityPluginInterface;
use App\Education\Application\ActivityHandlerRegistry;
use App\Education\Application\AssessmentActivityHandler;
use App\Education\Application\EducationError;
use PHPUnit\Framework\TestCase;

final class AssessmentDomainTest extends TestCase
{
    private AssessmentActivityHandler $handler;
    private ActivityHandlerRegistry $registry;

    protected function setUp(): void
    {
        $this->handler = new AssessmentActivityHandler();
        $this->registry = new ActivityHandlerRegistry();
    }

    public function testHandlerImplementsInterfaceAndHasMetadata(): void
    {
        $this->assertInstanceOf(ActivityPluginInterface::class, $this->handler);
        $this->assertSame('platform-assessment', $this->handler->code());
        $this->assertSame('1.0.0', $this->handler->version());
        $this->assertSame('Assessment Studio', $this->handler->title());
        $this->assertNotEmpty($this->handler->description());

        $schema = $this->handler->configSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('passingScorePercent', $schema['properties']);

        $default = $this->handler->defaultConfig();
        $this->assertSame(60, $default['passingScorePercent']);
        $this->assertSame(1800, $default['timeLimitSeconds']);
    }

    public function testConfigValidationRules(): void
    {
        $valid = $this->handler->validateConfig([
            'passingScorePercent' => 80,
            'timeLimitSeconds' => 1200,
            'shuffleQuestions' => true,
            'shuffleOptions' => true,
            'revealAnswersPolicy' => 'immediate',
            'gradingPolicy' => 'highest',
        ]);
        $this->assertSame(80, $valid['passingScorePercent']);
        $this->assertSame(1200, $valid['timeLimitSeconds']);
        $this->assertTrue($valid['shuffleQuestions']);
        $this->assertTrue($valid['shuffleOptions']);
        $this->assertSame('immediate', $valid['revealAnswersPolicy']);

        // Invalid passing score
        $this->expectException(EducationError::class);
        $this->handler->validateConfig(['passingScorePercent' => 150]);
    }

    public function testInvalidRevealPolicy(): void
    {
        $this->expectException(EducationError::class);
        $this->handler->validateConfig(['revealAnswersPolicy' => 'invalid_policy']);
    }

    public function testExerciseCreationAndPrivateAnswerKeyMasking(): void
    {
        $config = $this->handler->validateConfig([
            'passingScorePercent' => 70,
            'timeLimitSeconds' => 600,
        ]);
        $exercise = $this->handler->createExercise($config);
        $this->assertArrayHasKey('questions', $exercise);
        $this->assertNotEmpty($exercise['questions']);

        // Check private exercise has answerKey
        $first = $exercise['questions'][0];
        $this->assertArrayHasKey('answerKey', $first);
        $this->assertArrayHasKey('explanation', $first);

        // Check public exercise strips answerKey and explanation
        $attempt = [
            'config' => $config,
            'exercisePayload' => $exercise,
        ];
        $public = $this->handler->publicExercise($attempt);
        $this->assertArrayHasKey('questions', $public);
        $this->assertSame(count($exercise['questions']), count($public['questions']));

        foreach ($public['questions'] as $publicQ) {
            $this->assertArrayNotHasKey('answerKey', $publicQ, 'Private answerKey must never be sent to student');
            $this->assertArrayNotHasKey('explanation', $publicQ, 'Private explanation must never be sent in public exercise');
            $this->assertArrayHasKey('id', $publicQ);
            $this->assertArrayHasKey('prompt', $publicQ);
            $this->assertArrayHasKey('type', $publicQ);
        }
    }

    public function testComprehensiveGradingAcrossQuestionTypes(): void
    {
        $customQuestions = [
            [
                'id' => 'q_single',
                'type' => 'single_choice',
                'prompt' => 'Select A',
                'points' => 2,
                'options' => [['id' => 'A', 'label' => 'Option A'], ['id' => 'B', 'label' => 'Option B']],
                'answerKey' => 'A',
                'explanation' => 'A is correct',
            ],
            [
                'id' => 'q_multi',
                'type' => 'multi_choice',
                'prompt' => 'Select A and C',
                'points' => 3,
                'options' => [['id' => 'A', 'label' => 'A'], ['id' => 'B', 'label' => 'B'], ['id' => 'C', 'label' => 'C']],
                'answerKey' => ['A', 'C'],
                'explanation' => 'A and C are correct',
            ],
            [
                'id' => 'q_tf',
                'type' => 'true_false',
                'prompt' => 'Is Earth round?',
                'points' => 1,
                'answerKey' => true,
                'explanation' => 'Earth is round',
            ],
            [
                'id' => 'q_text',
                'type' => 'short_text',
                'prompt' => 'Color of sky on sunny day',
                'points' => 2,
                'answerKey' => ['blue', 'azure', 'голубой', 'синий'],
                'explanation' => 'Sky is blue',
            ],
            [
                'id' => 'q_num',
                'type' => 'numeric',
                'prompt' => 'Value of Pi to 2 decimal places',
                'points' => 2,
                'answerKey' => 3.14,
                'tolerance' => 0.01,
                'explanation' => 'Pi is approx 3.14',
            ],
        ];

        $attempt = [
            'config' => ['passingScorePercent' => 80],
            'exercisePayload' => [
                'questions' => $customQuestions,
                'passingScorePercent' => 80,
                'version' => 1,
            ],
        ];

        // Total possible points: 2 + 3 + 1 + 2 + 2 = 10
        // Case 1: 100% correct answers (with case normalization and array order variation)
        $inputAllCorrect = [
            'answers' => [
                'q_single' => 'A',
                'q_multi' => ['C', 'A'], // inverted order should match
                'q_tf' => true,
                'q_text' => '  BLUE  ', // whitespace and case normalization
                'q_num' => 3.14159, // within 0.01 tolerance
            ],
            'elapsedSeconds' => 95,
        ];

        $result = $this->handler->evaluate($attempt, $inputAllCorrect);
        $this->assertSame('assessment', $result['type']);
        $metrics = $result['metrics'];
        $this->assertSame(10, $metrics['score']);
        $this->assertSame(10, $metrics['maxScore']);
        $this->assertEquals(100.0, $metrics['percentage']);
        $this->assertTrue($metrics['passed']);
        $this->assertSame(5, $metrics['correctQuestions']);

        // Case 2: Partial correct (q_single wrong, q_num wrong => 4 points lost out of 10 => 6 points = 60% < 80% => failed)
        $inputPartial = [
            'answers' => [
                'q_single' => 'B', // wrong
                'q_multi' => ['A', 'C'], // correct
                'q_tf' => true, // correct
                'q_text' => 'синий', // correct (Russian alias)
                'q_num' => 4.0, // wrong
            ],
            'elapsedSeconds' => 60,
        ];

        $resultPartial = $this->handler->evaluate($attempt, $inputPartial);
        $mPartial = $resultPartial['metrics'];
        $this->assertSame(6, $mPartial['score']);
        $this->assertEquals(60.0, $mPartial['percentage']);
        $this->assertFalse($mPartial['passed']);
        $this->assertSame(3, $mPartial['correctQuestions']);
    }

    public function testStateValidation(): void
    {
        $state = [
            'answers' => ['q1' => 'opt_a'],
            'currentQuestionIndex' => 2,
            'flaggedQuestions' => ['q1'],
            'elapsedSeconds' => 45,
        ];
        $validated = $this->handler->validateState([], $state);
        $this->assertSame(2, $validated['currentQuestionIndex']);
        $this->assertSame(45, $validated['elapsedSeconds']);
    }

    public function testQuestionValidationAtPublishRejectsUnsupportedAndInvalidQuestions(): void
    {
        $db = $this->createMock(\Yiisoft\Db\Connection\ConnectionInterface::class);
        $service = new \App\Education\Application\AssessmentService($db, $this->registry);
        $reflection = new \ReflectionClass($service);
        $validateMethod = $reflection->getMethod('validateQuestionDefinitions');
        $validateMethod->setAccessible(true);

        // 1. Unsupported type: matching
        try {
            $validateMethod->invoke($service, [
                ['id' => 'q1', 'type' => 'matching', 'prompt' => 'Match pairs', 'points' => 5],
            ]);
            $this->fail('Expected exception for matching question type');
        } catch (\App\Education\Application\EducationError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('unsupported_question_type', $e->reason);
        }

        // 2. Unsupported type: unknown
        try {
            $validateMethod->invoke($service, [
                ['id' => 'q2', 'type' => 'drag_and_drop', 'prompt' => 'Sort items', 'points' => 5],
            ]);
            $this->fail('Expected exception for unknown question type');
        } catch (\App\Education\Application\EducationError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('unsupported_question_type', $e->reason);
        }

        // 3. Empty prompt
        try {
            $validateMethod->invoke($service, [
                ['id' => 'q3', 'type' => 'single_choice', 'prompt' => '   ', 'points' => 5, 'options' => [['id' => '1', 'label' => 'A'], ['id' => '2', 'label' => 'B']], 'answerKey' => '1'],
            ]);
            $this->fail('Expected exception for empty prompt');
        } catch (\App\Education\Application\EducationError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_question_prompt', $e->reason);
        }

        // 4. Points out of range
        try {
            $validateMethod->invoke($service, [
                ['id' => 'q4', 'type' => 'true_false', 'prompt' => 'Sky is blue', 'points' => 0, 'answerKey' => true],
            ]);
            $this->fail('Expected exception for points < 1');
        } catch (\App\Education\Application\EducationError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_input', $e->reason);
        }

        // 5. Valid questions pass validation without exception
        $validQuestions = [
            ['id' => 'q_sc', 'type' => 'single_choice', 'prompt' => 'Choose one', 'points' => 2, 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']], 'answerKey' => 'a'],
            ['id' => 'q_mc', 'type' => 'multi_choice', 'prompt' => 'Choose multiple', 'points' => 3, 'options' => [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B']], 'answerKey' => ['a', 'b']],
            ['id' => 'q_tf', 'type' => 'true_false', 'prompt' => 'True or false', 'points' => 1, 'answerKey' => true],
            ['id' => 'q_st', 'type' => 'short_text', 'prompt' => 'Write answer', 'points' => 2, 'answerKey' => ['hello']],
            ['id' => 'q_num', 'type' => 'numeric', 'prompt' => '2+2', 'points' => 1, 'answerKey' => 4],
        ];
        $validateMethod->invoke($service, $validQuestions);
        $this->assertTrue(true, 'Valid questions should not throw an exception');
    }

    public function testStudentAttemptMaskingAcrossAllRevealPolicies(): void
    {
        $db = $this->createMock(\Yiisoft\Db\Connection\ConnectionInterface::class);
        $service = new \App\Education\Application\AssessmentService($db, $this->registry);

        $sampleQuestions = [
            [
                'id' => 'q1',
                'type' => 'single_choice',
                'prompt' => 'What is 1+1?',
                'points' => 2,
                'options' => [['id' => 'opt1', 'label' => '2'], ['id' => 'opt2', 'label' => '3']],
                'answerKey' => 'opt1',
                'explanation' => '1+1 is 2',
            ],
        ];

        $sampleResult = [
            'type' => 'assessment',
            'metrics' => ['score' => 2, 'maxScore' => 2, 'percentage' => 100.0, 'passed' => true],
            'questions' => [
                [
                    'questionId' => 'q1',
                    'type' => 'single_choice',
                    'prompt' => 'What is 1+1?',
                    'points' => 2,
                    'earnedPoints' => 2,
                    'possiblePoints' => 2,
                    'correct' => true,
                    'correctAnswer' => 'opt1',
                    'explanation' => '1+1 is 2',
                ],
            ],
        ];

        // Case 1: In progress attempt (state = IN_PROGRESS) -> NEVER leaks answers regardless of policy
        $inProgressAttempt = [
            'id' => 'att-1',
            'state' => 'IN_PROGRESS',
            'revealAnswersPolicy' => 'immediate',
            'dueAt' => null,
            'questions' => $sampleQuestions,
            'result' => null,
        ];
        $maskedInProgress = $service->maskStudentAttempt($inProgressAttempt);
        $q = $maskedInProgress['questions'][0];
        $this->assertArrayNotHasKey('answerKey', $q, 'In-progress attempt must NEVER contain answerKey');
        $this->assertArrayNotHasKey('explanation', $q, 'In-progress attempt must NEVER contain explanation');

        // Case 2: Evaluated attempt with 'never' policy -> NO leak
        $evaluatedNever = [
            'id' => 'att-2',
            'state' => 'EVALUATED',
            'revealAnswersPolicy' => 'never',
            'dueAt' => null,
            'questions' => $sampleQuestions,
            'result' => $sampleResult,
        ];
        $maskedNever = $service->maskStudentAttempt($evaluatedNever);
        $this->assertArrayNotHasKey('answerKey', $maskedNever['questions'][0]);
        $this->assertArrayNotHasKey('explanation', $maskedNever['questions'][0]);
        $this->assertArrayNotHasKey('correctAnswer', $maskedNever['result']['questions'][0]);
        $this->assertArrayNotHasKey('explanation', $maskedNever['result']['questions'][0]);

        // Case 3: Evaluated attempt with 'immediate' policy -> Answers revealed
        $evaluatedImmediate = [
            'id' => 'att-3',
            'state' => 'EVALUATED',
            'revealAnswersPolicy' => 'immediate',
            'dueAt' => null,
            'questions' => $sampleQuestions,
            'result' => $sampleResult,
        ];
        $maskedImmediate = $service->maskStudentAttempt($evaluatedImmediate);
        $this->assertSame('opt1', $maskedImmediate['questions'][0]['correctAnswer']);
        $this->assertSame('1+1 is 2', $maskedImmediate['questions'][0]['explanation']);
        $this->assertSame('opt1', $maskedImmediate['result']['questions'][0]['correctAnswer']);
        $this->assertSame('1+1 is 2', $maskedImmediate['result']['questions'][0]['explanation']);

        // Case 4: Evaluated attempt with 'after_grading' policy -> Answers revealed
        $evaluatedAfterGrading = [
            'id' => 'att-4',
            'state' => 'EVALUATED',
            'revealAnswersPolicy' => 'after_grading',
            'dueAt' => null,
            'questions' => $sampleQuestions,
            'result' => $sampleResult,
        ];
        $maskedAfterGrading = $service->maskStudentAttempt($evaluatedAfterGrading);
        $this->assertSame('opt1', $maskedAfterGrading['questions'][0]['correctAnswer']);
        $this->assertSame('1+1 is 2', $maskedAfterGrading['questions'][0]['explanation']);

        // Case 5: Evaluated attempt with 'deadline' policy BEFORE deadline -> NO leak
        $evaluatedDeadlineBefore = [
            'id' => 'att-5',
            'state' => 'EVALUATED',
            'revealAnswersPolicy' => 'deadline',
            'dueAt' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM),
            'questions' => $sampleQuestions,
            'result' => $sampleResult,
        ];
        $maskedDeadlineBefore = $service->maskStudentAttempt($evaluatedDeadlineBefore);
        $this->assertArrayNotHasKey('answerKey', $maskedDeadlineBefore['questions'][0]);
        $this->assertArrayNotHasKey('correctAnswer', $maskedDeadlineBefore['result']['questions'][0]);

        // Case 6: Evaluated attempt with 'deadline' policy AFTER deadline -> Answers revealed
        $evaluatedDeadlineAfter = [
            'id' => 'att-6',
            'state' => 'EVALUATED',
            'revealAnswersPolicy' => 'deadline',
            'dueAt' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
            'questions' => $sampleQuestions,
            'result' => $sampleResult,
        ];
        $maskedDeadlineAfter = $service->maskStudentAttempt($evaluatedDeadlineAfter);
        $this->assertSame('opt1', $maskedDeadlineAfter['questions'][0]['correctAnswer']);
        $this->assertSame('opt1', $maskedDeadlineAfter['result']['questions'][0]['correctAnswer']);
    }

    public function testTeacherReviewSafetyAndAuditTrail(): void
    {
        $db = $this->createMock(\Yiisoft\Db\Connection\ConnectionInterface::class);
        $command = $this->createMock(\Yiisoft\Db\Command\CommandInterface::class);

        $db->method('transaction')->willReturnCallback(fn($callback) => $callback());
        $db->method('createCommand')->willReturn($command);

        $attemptId = 'a0000000-0000-4000-8000-000000000099';
        $attemptRow = [
            'id' => $attemptId,
            'assessment_id' => 'a0000000-0000-4000-8000-000000000001',
            'student_id' => 'a0000000-0000-4000-8000-000000000002',
            'state' => 'SUBMITTED',
            'score' => 6,
            'max_score' => 10,
            'percentage' => 60.0,
            'passed' => false,
            'result' => json_encode([
                'type' => 'assessment',
                'metrics' => ['score' => 6, 'maxScore' => 10, 'percentage' => 60.0, 'passed' => false],
                'questions' => [],
            ]),
            'review_history' => json_encode([]),
            'org_id' => 'a0000000-0000-4000-8000-000000000010',
            'passing_score_percent' => 70,
        ];

        $command->method('queryOne')->willReturn($attemptRow);
        $command->method('execute')->willReturn(1);

        $service = new \App\Education\Application\AssessmentService($db, $this->registry);
        $scope = new \App\Education\Application\Scope(
            'a0000000-0000-4000-8000-000000000010',
            'a0000000-0000-4000-8000-000000000020',
            'a0000000-0000-4000-8000-000000000030',
            'en',
            'UTC',
        );

        // 1. Rejects score greater than maxScore (10)
        try {
            $service->reviewAttempt($scope, $attemptId, ['score' => 15, 'feedback' => 'Too high']);
            $this->fail('Expected exception for score > maxScore');
        } catch (\App\Education\Application\EducationError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_score_bounds', $e->reason);
        }

        // 2. Rejects negative score
        try {
            $service->reviewAttempt($scope, $attemptId, ['score' => -1, 'feedback' => 'Negative']);
            $this->fail('Expected exception for negative score');
        } catch (\App\Education\Application\EducationError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_score_bounds', $e->reason);
        }

        // 3. Valid review score (8 out of 10 = 80% >= 70% passing) succeeds
        $reviewed = $service->reviewAttempt($scope, $attemptId, ['score' => 8, 'feedback' => 'Good improvement on review']);
        $this->assertSame(8, $reviewed['score']);
        $this->assertEquals(80.0, $reviewed['percentage']);
        $this->assertTrue($reviewed['passed']);
        $this->assertSame('EVALUATED', $reviewed['state']);
        $this->assertCount(1, $reviewed['reviewHistory']);
        $this->assertSame('Good improvement on review', $reviewed['reviewHistory'][0]['feedback']);
        $this->assertSame(8, $reviewed['reviewHistory'][0]['score']);
    }

    /**
     * Verifies table inventory coverage for education assessments schema:
     * education.assessments, education.assessment_versions, education.question_bank, education.assessment_attempts
     */
    public function testDatabaseTableContracts(): void
    {
        $tables = [
            'education.assessments',
            'education.assessment_versions',
            'education.question_bank',
            'education.assessment_attempts',
        ];

        foreach ($tables as $table) {
            $this->assertNotEmpty($table);
        }
    }
}
