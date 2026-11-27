<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Activity\Application\ActivityDomainPolicyInterface;
use App\Activity\Application\ActivityEngineService;
use App\Activity\Application\ActivityPayload;
use App\Activity\Application\ActivityPluginRegistry;
use App\Activity\Infrastructure\DefaultActivityDomainPolicy;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Rbac\Application\RoleRepository;
use App\Education\Infrastructure\EducationActivityDomainPolicy;
use App\Notifications\Application\NotificationsService;
use App\Realtime\Application\RealtimeChannelAuthorizerInterface;
use App\Realtime\Application\RealtimeEvent;
use App\Realtime\Application\RealtimeService;
use App\Shared\Application\HttpError;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface as RedisClientInterface;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final class Platform10And11DomainTest extends TestCase
{
    private const ORG_ID = '01950000-0000-7000-8000-000000000010';
    private const TEACHER_ACCOUNT_ID = '01950000-0000-7000-8000-000000000020';
    private const TEACHER_PERSON_ID = '01950000-0000-7000-8000-000000000021';
    private const OTHER_TEACHER_ACCOUNT_ID = '01950000-0000-7000-8000-000000000022';
    private const OTHER_TEACHER_PERSON_ID = '01950000-0000-7000-8000-000000000023';
    private const STUDENT_ACCOUNT_ID = '01950000-0000-7000-8000-000000000030';
    private const STUDENT_PERSON_ID = '01950000-0000-7000-8000-000000000031';
    private const PARENT_ACCOUNT_ID = '01950000-0000-7000-8000-000000000040';
    private const PARENT_PERSON_ID = '01950000-0000-7000-8000-000000000041';
    private const ACTIVITY_ID = '01950000-0000-7000-8000-000000000100';
    private const VERSION_ACTIVE_ID = '01950000-0000-7000-8000-000000000200';
    private const VERSION_PINNED_ID = '01950000-0000-7000-8000-000000000201';
    private const ASSIGNMENT_ID = '01950000-0000-7000-8000-000000000300';
    private const ATTEMPT_ID = '01950000-0000-7000-8000-000000000400';

    public function testAllFiveUniversalPluginsAreRegisteredWithV2Contracts(): void
    {
        $registry = new ActivityPluginRegistry();
        $descriptors = $registry->describeAll();
        $codes = array_column($descriptors, 'code');

        foreach (['platform-assessment', 'platform-typing', 'platform-math-sprint', 'platform-logic', 'platform-memory'] as $code) {
            $this->assertContains($code, $codes);
            $plugin = $registry->get($code);
            $this->assertSame(2, $plugin->version());
            $this->assertNotEmpty($plugin->capabilities());
            $this->assertIsArray($plugin->defaultConfig());
        }

        // GATE 02.2: safeParticipantConfigKeys contract
        $this->assertSame([], $registry->get('platform-assessment')->safeParticipantConfigKeys());
        $this->assertSame(['language'], $registry->get('platform-typing')->safeParticipantConfigKeys());
        $this->assertSame([], $registry->get('platform-math-sprint')->safeParticipantConfigKeys());
        $this->assertSame([], $registry->get('platform-logic')->safeParticipantConfigKeys());
        $this->assertSame([], $registry->get('platform-memory')->safeParticipantConfigKeys());

        $this->expectException(HttpError::class);
        $registry->get('non-existent-plugin');
    }

    public function testAssessmentPluginSupportsOrderingPartialCreditAndLongTextReview(): void
    {
        $registry = new ActivityPluginRegistry();
        $plugin = $registry->get('platform-assessment');
        $cfg = array_merge($plugin->defaultConfig(), ['partialCredit' => true, 'passingScorePercent' => 60]);
        $exercise = $plugin->generateExercise($cfg, 777, [
            'questions' => [
                ['id' => 'q1', 'questionType' => 'multi_choice', 'content' => ['prompt' => 'Primes', 'options' => ['2', '3', '4']], 'answerKey' => ['2', '3'], 'defaultPoints' => 2],
                ['id' => 'q2', 'questionType' => 'ordering', 'content' => ['prompt' => 'Layers', 'items' => ['L1', 'L2', 'L3']], 'answerKey' => ['L1', 'L2', 'L3'], 'defaultPoints' => 3],
                ['id' => 'q3', 'questionType' => 'matching', 'content' => ['pairs' => ['HTTP' => '80', 'HTTPS' => '443']], 'answerKey' => ['HTTP' => '80', 'HTTPS' => '443'], 'defaultPoints' => 2],
                ['id' => 'q4', 'questionType' => 'long_text', 'content' => ['prompt' => 'Explain Outbox'], 'answerKey' => null, 'defaultPoints' => 5],
            ],
        ]);

        // Public projection must contain questions without leaking into answer fields
        $rawQuestions = $exercise['questions'] ?? null;
        $questions = is_array($rawQuestions) ? $rawQuestions : [];
        $this->assertCount(4, $questions);
        foreach ($questions as $q) {
            $this->assertIsArray($q);
            $this->assertArrayNotHasKey('answerKey', $q);
        }

        // Partial credit on q1 (1/2) + partial ordering on q2 (L1 matched only = 1/3 -> 1pt) + 1/2 matching (1pt) + long_text pending review
        $eval = $plugin->evaluate($cfg, $exercise, [
            'answers' => [
                'q1' => ['2'],
                'q2' => ['L1', 'L3', 'L2'],
                'q3' => ['HTTP' => '80', 'HTTPS' => '22'],
                'q4' => 'Outbox guarantees transactional event durability.',
            ],
        ]);

        $this->assertTrue($eval['requiresManualReview']);
        $this->assertFalse($eval['passed']);
        $this->assertFalse($eval['telemetryVerified']);
        $this->assertSame(3.0, $eval['rawScore']);
        $this->assertSame(12.0, $eval['maxScore']);
    }

    public function testTypingPluginEvaluatesRussianEnglishAndZenModesOnServer(): void
    {
        $registry = new ActivityPluginRegistry();
        $plugin = $registry->get('platform-typing');
        $cfg = array_merge($plugin->defaultConfig(), ['mode' => 'words', 'language' => 'ru', 'wordCount' => 6, 'minAccuracy' => 80]);
        $ex = $plugin->generateExercise($cfg, 101);

        $prompt = (string) $ex['promptText'];
        $this->assertNotEmpty($prompt);

        $eval = $plugin->evaluate($cfg, $ex, [
            'typedText' => $prompt,
            'elapsedSeconds' => 30.0,
        ], ['backspaceCount' => 2]);

        $this->assertSame(100.0, $eval['percentage']);
        $this->assertTrue($eval['passed']);
        $this->assertFalse($eval['telemetryVerified']);
        $this->assertGreaterThan(0.0, $eval['metrics']['wpm']);

        // Zen mode
        $zenCfg = array_merge($plugin->defaultConfig(), ['mode' => 'zen']);
        $zenEx = $plugin->generateExercise($zenCfg, 5);
        $this->assertSame('', $zenEx['promptText']);
        $zenEval = $plugin->evaluate($zenCfg, $zenEx, ['typedText' => 'Freeform flow typing', 'elapsedSeconds' => 15.0]);
        $this->assertSame(100.0, $zenEval['percentage']);
    }

    public function testMathSprintLogicAndMemoryPluginsGenerateAndScoreDeterministically(): void
    {
        $registry = new ActivityPluginRegistry();
        $math = $registry->get('platform-math-sprint');
        $mathEx = $math->generateExercise(['operations' => ['add', 'sub'], 'questionCount' => 5], 555);
        $rawMath = $mathEx['items'] ?? null;
        $mathItems = is_array($rawMath) ? $rawMath : [];
        $this->assertCount(5, $mathItems);
        $mathEval = $math->evaluate(['questionCount' => 5], $mathEx, ['answers' => $mathEx['_answerKey'], 'elapsedSeconds' => 12.0]);
        $this->assertSame(100.0, $mathEval['percentage']);
        $this->assertTrue($mathEval['passed']);

        $logic = $registry->get('platform-logic');
        $logicEx = $logic->generateExercise(['rounds' => 4], 999);
        $rawLogic = $logicEx['rounds'] ?? null;
        $logicRounds = is_array($rawLogic) ? $rawLogic : [];
        $this->assertCount(4, $logicRounds);
        $logicEval = $logic->evaluate([], $logicEx, ['answers' => $logicEx['_answerKey']]);
        $this->assertSame(100.0, $logicEval['percentage']);
        $this->assertTrue($logicEval['passed']);

        $memory = $registry->get('platform-memory');
        $memEx = $memory->generateExercise(['gridSize' => 4, 'rounds' => 3], 333);
        $rawMem = $memEx['rounds'] ?? null;
        $memRounds = is_array($rawMem) ? $rawMem : [];
        $this->assertCount(3, $memRounds);
        $memEval = $memory->evaluate([], $memEx, ['answers' => $memEx['_answerKey']]);
        $this->assertSame(100.0, $memEval['percentage']);
        $this->assertTrue($memEval['passed']);
    }

    public function testActivityPayloadAndRealtimeEventContracts(): void
    {
        $this->assertSame('{}', ActivityPayload::encodeObject([]));
        $this->assertSame(['a' => 1], ActivityPayload::decodeObject('{"a":1}'));
        $this->assertSame(['x', '42'], ActivityPayload::stringList(['x', 42, ['nested']]));
        $this->assertSame([1, 5], ActivityPayload::intList(['1', 5, 'bad']));

        $evt = new RealtimeEvent(
            seq: 42,
            eventId: '01950000-0000-7000-8000-000000000001',
            organizationId: '01950000-0000-7000-8000-000000000002',
            channel: 'room:test',
            eventType: 'chat.message.created',
            aggregateType: 'message',
            aggregateId: '01950000-0000-7000-8000-000000000003',
            actorAccountId: null,
            actorPersonId: null,
            payload: ['text' => 'hello'],
            targetPersonIds: ['01950000-0000-7000-8000-000000000004'],
            createdAt: '2026-11-25T10:00:00Z',
        );
        $arr = $evt->toArray();
        $this->assertSame(42, $arr['seq']);
        $this->assertSame('room:test', $arr['channel']);
        $this->assertFalse($arr['ephemeral']);
    }

    public function testGate01ActivityEngineHasNoEducationSqlAndMigration002IsIdempotent(): void
    {
        $engineSource = file_get_contents(dirname(__DIR__, 2) . '/src/Activity/Application/ActivityEngineService.php');
        $this->assertIsString($engineSource);
        $this->assertStringNotContainsString('education.', $engineSource);

        $defaultPolicySource = file_get_contents(dirname(__DIR__, 2) . '/src/Activity/Infrastructure/DefaultActivityDomainPolicy.php');
        $this->assertIsString($defaultPolicySource);
        $this->assertStringNotContainsString('education.', $defaultPolicySource);

        $migrationSql = file_get_contents(dirname(__DIR__, 2) . '/migrations/activity/002_activity_consolidation.sql');
        $this->assertIsString($migrationSql);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS revoked_at', $migrationSql);
        $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS reveal_answers_policy', $migrationSql);
        $this->assertStringContainsString('ON CONFLICT (id) DO NOTHING', $migrationSql);
        $this->assertStringContainsString('source_legacy_type', $migrationSql);
        $this->assertStringContainsString('source_legacy_id', $migrationSql);
        $this->assertStringNotContainsString('DROP TABLE', strtoupper($migrationSql));
    }

    public function testGate01EducationActivityDomainPolicyEnforcesGroupsParentsAndPractice(): void
    {
        $perms = $this->makePermissionService([
            self::TEACHER_ACCOUNT_ID => ['activity.use', 'activity.create', 'activity.assign', 'activity.review'],
            self::STUDENT_ACCOUNT_ID => ['activity.use'],
            self::PARENT_ACCOUNT_ID => ['activity.use', 'education.parent.use'],
        ]);

        $db = $this->makeRoutingDb(
            scalarHandler: static function (string $sql, array $params): mixed {
                if (str_contains($sql, 'education.can_teach_group') && str_contains($sql, 'education.groups')) {
                    return ($params[':gid'] ?? '') === '01950000-0000-7000-8000-000000000777';
                }
                if (str_contains($sql, 'education.group_students')) {
                    return ($params[':tid'] ?? '') === '01950000-0000-7000-8000-000000000777'
                        && ($params[':person'] ?? '') === self::STUDENT_PERSON_ID;
                }
                if (str_contains($sql, 'education.student_guardians')) {
                    return ($params[':guardian'] ?? '') === self::PARENT_PERSON_ID
                        && ($params[':studentPerson'] ?? '') === self::STUDENT_PERSON_ID;
                }
                return false;
            },
            allHandler: static function (string $sql, array $params): array {
                if (str_contains($sql, 'FROM activity.assignments')) {
                    return [[
                        'id' => self::ASSIGNMENT_ID,
                        'targetType' => 'group',
                        'targetId' => '01950000-0000-7000-8000-000000000777',
                    ]];
                }
                return [];
            },
        );

        $policy = new EducationActivityDomainPolicy($db, $perms);
        $teacher = $this->identity(self::TEACHER_ACCOUNT_ID, self::TEACHER_PERSON_ID);
        $student = $this->identity(self::STUDENT_ACCOUNT_ID, self::STUDENT_PERSON_ID);
        $parent = $this->identity(self::PARENT_ACCOUNT_ID, self::PARENT_PERSON_ID);

        // Private DRAFT is visible to author, NEVER to student
        $draftActivity = [
            'id' => self::ACTIVITY_ID,
            'authorPersonId' => self::TEACHER_PERSON_ID,
            'status' => 'DRAFT',
            'visibility' => 'private',
            'config' => [],
        ];
        $this->assertTrue($policy->canAccessActivity($teacher, self::ORG_ID, $draftActivity));
        $this->assertFalse($policy->canAccessActivity($student, self::ORG_ID, $draftActivity));

        // Group PUBLISHED activity: assigned student allowed, open practice disallowed
        $groupActivity = [
            'id' => self::ACTIVITY_ID,
            'authorPersonId' => self::TEACHER_PERSON_ID,
            'status' => 'PUBLISHED',
            'visibility' => 'group',
            'config' => [],
        ];
        $this->assertTrue($policy->canAccessActivity($student, self::ORG_ID, $groupActivity));
        $this->assertFalse($policy->canStartOpenPractice($student, self::ORG_ID, $groupActivity));

        // examOnly blocks open practice even if public_catalog
        $this->assertFalse($policy->canStartOpenPractice($student, self::ORG_ID, [
            'id' => self::ACTIVITY_ID,
            'status' => 'PUBLISHED',
            'visibility' => 'public_catalog',
            'config' => ['examOnly' => true],
        ]));

        // Teacher can assign only to taught group
        $this->assertTrue($policy->canAssignTarget($teacher, self::ORG_ID, 'group', '01950000-0000-7000-8000-000000000777'));
        $this->assertFalse($policy->canAssignTarget($teacher, self::ORG_ID, 'group', '01950000-0000-7000-8000-000000000999'));

        // Parent confirmed guardian can view child's attempt & analytics, cannot view unrelated person
        $this->assertTrue($policy->canAccessParticipantAttempt($parent, self::ORG_ID, self::STUDENT_PERSON_ID, null, self::TEACHER_PERSON_ID));
        $this->assertFalse($policy->canAccessParticipantAttempt($parent, self::ORG_ID, self::OTHER_TEACHER_PERSON_ID, null, self::TEACHER_PERSON_ID));
        $this->assertTrue($policy->canViewParticipantAnalytics($parent, self::ORG_ID, self::STUDENT_PERSON_ID));
        $this->assertFalse($policy->canViewParticipantAnalytics($parent, self::ORG_ID, self::OTHER_TEACHER_PERSON_ID));
    }

    public function testGate02StartAttemptEnforcesAssignmentIntegrityPinnedVersionAndConfigWhitelist(): void
    {
        $student = $this->identity(self::STUDENT_ACCOUNT_ID, self::STUDENT_PERSON_ID);
        $sink = new class {
            /** @var list<array<string, mixed>> */
            public array $inserts = [];
        };

        $policy = $this->createMock(ActivityDomainPolicyInterface::class);
        $policy->method('canAccessActivity')->willReturn(true);
        $policy->method('canStartOpenPractice')->willReturn(false);
        $policy->method('isAssignmentRecipient')->willReturn(true);
        $policy->method('canAccessParticipantAttempt')->willReturn(true);

        $db = $this->makeRoutingDb(
            oneHandler: static function (string $sql, array $params): ?array {
                if (str_contains($sql, 'FROM activity.items i')) {
                    return [
                        'id' => self::ACTIVITY_ID,
                        'pluginCode' => 'platform-assessment',
                        'pluginTitle' => 'Assessment Studio 2.0',
                        'category' => 'assessment',
                        'authorPersonId' => self::TEACHER_PERSON_ID,
                        'title' => 'Midterm',
                        'description' => '',
                        'status' => 'PUBLISHED',
                        'visibility' => 'organization',
                        'tagsJson' => '[]',
                        'activeVersionId' => self::VERSION_ACTIVE_ID,
                        'createdAt' => '2026-11-25T10:00:00Z',
                        'updatedAt' => '2026-11-25T10:00:00Z',
                        'versionNumber' => 2,
                        'instructions' => '',
                        'configJson' => '{"examOnly":true,"passingScorePercent":75}',
                        'contentJson' => '{"questions":[]}',
                        'timeLimitSeconds' => 600,
                        'maxAttempts' => 1,
                        'passingScorePercent' => 75,
                        'gradingPolicy' => 'highest',
                        'revealAnswersPolicy' => 'never',
                    ];
                }
                if (str_contains($sql, 'FROM activity.assignments')) {
                    return [
                        'id' => self::ASSIGNMENT_ID,
                        'organizationId' => self::ORG_ID,
                        'activityId' => self::ACTIVITY_ID,
                        'versionId' => self::VERSION_PINNED_ID,
                        'assignedByPersonId' => self::TEACHER_PERSON_ID,
                        'targetType' => 'student',
                        'targetId' => self::STUDENT_PERSON_ID,
                        'opensAt' => null,
                        'dueAt' => null,
                        'allowLate' => false,
                        'configOverrideJson' => '{"shuffleQuestions":true,"questions":[{"hacked":true}]}',
                        'status' => 'ACTIVE',
                        'revokedAt' => null,
                    ];
                }
                if (str_contains($sql, 'FROM activity.versions')) {
                    // Must query pinned version, never active version
                    if (($params[':vid'] ?? '') !== self::VERSION_PINNED_ID) {
                        return null;
                    }
                    return [
                        'id' => self::VERSION_PINNED_ID,
                        'configJson' => '{"timeLimitSeconds":300,"revealAnswersPolicy":"never","passingScorePercent":80}',
                        'contentJson' => '{"questions":[{"id":"q1","questionType":"single_choice","content":{"prompt":"1+1","options":["2","3"]},"answerKey":"2","points":2}]}',
                        'timeLimitSeconds' => 300,
                        'maxAttempts' => 2,
                        'passingScorePercent' => 80,
                    ];
                }
                if (str_contains($sql, 'FROM activity.attempts a')) {
                    return [
                        'id' => self::ATTEMPT_ID,
                        'activityId' => self::ACTIVITY_ID,
                        'versionId' => self::VERSION_PINNED_ID,
                        'assignmentId' => self::ASSIGNMENT_ID,
                        'participantPersonId' => self::STUDENT_PERSON_ID,
                        'state' => 'IN_PROGRESS',
                        'revision' => 1,
                        'seed' => 123,
                        'cfgJson' => '{"timeLimitSeconds":300,"passingScorePercent":80,"revealAnswersPolicy":"never","shuffleQuestions":true}',
                        'exJson' => '{"questions":[{"id":"q1","questionType":"single_choice","content":{"prompt":"1+1","options":["2","3"]},"points":2}],"_answerKeys":{"q1":{"answerKey":"2","explanation":"1+1=2"}}}',
                        'ansJson' => '{}',
                        'telJson' => '{}',
                        'evJson' => '{}',
                        'rawScore' => null,
                        'maxScore' => null,
                        'percentage' => null,
                        'passed' => null,
                        'startedAt' => '2026-11-25T10:00:00Z',
                        'expiresAt' => '2099-01-01T00:00:00Z',
                        'submittedAt' => null,
                        'evaluatedAt' => null,
                        'reviewNotes' => null,
                        'pluginCode' => 'platform-assessment',
                        'activityTitle' => 'Midterm',
                        'authorPersonId' => self::TEACHER_PERSON_ID,
                        'versionRevealPolicy' => 'never',
                        'versionConfigJson' => '{"revealAnswersPolicy":"never"}',
                        'assignmentDueAt' => null,
                    ];
                }
                return null;
            },
            scalarHandler: static function (string $sql, array $params) use ($sink): mixed {
                if (str_contains($sql, 'INSERT INTO activity.attempts')) {
                    $sink->inserts[] = $params;
                    return self::ATTEMPT_ID;
                }
                if (str_contains($sql, 'SELECT count(*) FROM activity.attempts')) {
                    return 0;
                }
                return null;
            },
        );

        $engine = $this->makeEngine($db, [self::STUDENT_ACCOUNT_ID => ['activity.use']], $policy);

        // 1. Open practice without assignmentId is rejected with 403 assignment_required
        try {
            $engine->startAttempt($student, self::ORG_ID, self::ACTIVITY_ID, []);
            $this->fail('Expected assignment_required');
        } catch (HttpError $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('assignment_required', $e->reason);
        }

        // 2. Controlled assignment uses pinned version & strips malicious client config overrides
        $started = $engine->startAttempt($student, self::ORG_ID, self::ACTIVITY_ID, [
            'assignmentId' => self::ASSIGNMENT_ID,
            'config' => [
                'timeLimitSeconds' => 999999,
                'passingScorePercent' => 0,
                'revealAnswersPolicy' => 'immediate',
                'questions' => [],
            ],
        ]);

        $this->assertSame(self::VERSION_PINNED_ID, $started['versionId']);
        $this->assertCount(1, $sink->inserts);
        $firstInsert = $sink->inserts[0];
        $this->assertSame(self::VERSION_PINNED_ID, $firstInsert[':vid']);
        $storedCfg = json_decode((string) $firstInsert[':cfg'], true);
        $this->assertIsArray($storedCfg);
        $this->assertSame(300, $storedCfg['timeLimitSeconds']);
        $this->assertSame(80, $storedCfg['passingScorePercent']);
        $this->assertSame('never', $storedCfg['revealAnswersPolicy']);
        $this->assertTrue($storedCfg['shuffleQuestions']);
        $this->assertArrayNotHasKey('questions', $storedCfg);

        // Answer keys must NEVER be leaked in IN_PROGRESS attempt
        $startedEx = ActivityPayload::assoc($started['exercise'] ?? null);
        $this->assertArrayNotHasKey('_answerKeys', $startedEx);
    }

    public function testGate02AnswerDisclosureEnforcesAllPoliciesAndMasksEvaluationBreakdown(): void
    {
        $student = $this->identity(self::STUDENT_ACCOUNT_ID, self::STUDENT_PERSON_ID);
        $author = $this->identity(self::TEACHER_ACCOUNT_ID, self::TEACHER_PERSON_ID);

        $makeEngineForAttempt = function (string $state, string $policyName, ?string $dueAt): ActivityEngineService {
            $policy = $this->createMock(ActivityDomainPolicyInterface::class);
            $policy->method('canAccessParticipantAttempt')->willReturn(true);

            $db = $this->makeRoutingDb(
                oneHandler: static fn(string $sql): ?array => str_contains($sql, 'FROM activity.attempts a')
                    ? [
                        'id' => self::ATTEMPT_ID,
                        'activityId' => self::ACTIVITY_ID,
                        'versionId' => self::VERSION_PINNED_ID,
                        'assignmentId' => self::ASSIGNMENT_ID,
                        'participantPersonId' => self::STUDENT_PERSON_ID,
                        'state' => $state,
                        'revision' => 2,
                        'seed' => 123,
                        'cfgJson' => '{}',
                        'exJson' => json_encode([
                            'rounds' => [['id' => 'r1', 'prompt' => '2, 4, 6, ?']],
                            '_answerKey' => ['r1' => '8'],
                            '_explanations' => ['r1' => 'Add 2'],
                        ], JSON_THROW_ON_ERROR),
                        'ansJson' => '{"r1":"8"}',
                        'telJson' => '{}',
                        'evJson' => json_encode([
                            'rawScore' => 1,
                            'maxScore' => 1,
                            'percentage' => 100,
                            'passed' => true,
                            'breakdown' => [[
                                'itemId' => 'r1',
                                'correct' => true,
                                'expected' => '8',
                                'explanation' => 'Add 2',
                            ]],
                        ], JSON_THROW_ON_ERROR),
                        'rawScore' => 1,
                        'maxScore' => 1,
                        'percentage' => 100,
                        'passed' => true,
                        'startedAt' => '2026-11-25T10:00:00Z',
                        'expiresAt' => null,
                        'submittedAt' => '2026-11-25T10:05:00Z',
                        'evaluatedAt' => '2026-11-25T10:05:00Z',
                        'reviewNotes' => null,
                        'pluginCode' => 'platform-logic',
                        'activityTitle' => 'Logic',
                        'authorPersonId' => self::TEACHER_PERSON_ID,
                        'versionRevealPolicy' => $policyName,
                        'versionConfigJson' => json_encode(['revealAnswersPolicy' => $policyName], JSON_THROW_ON_ERROR),
                        'assignmentDueAt' => $dueAt,
                    ]
                    : null,
            );

            return $this->makeEngine($db, [
                self::STUDENT_ACCOUNT_ID => ['activity.use'],
                self::TEACHER_ACCOUNT_ID => ['activity.use', 'activity.review'],
            ], $policy);
        };

        // 1. 'never' hides answers & breakdown expected/explanation even when EVALUATED for student
        $resNever = $makeEngineForAttempt('EVALUATED', 'never', null)->getAttempt($student, self::ORG_ID, self::ATTEMPT_ID);
        $neverEx = ActivityPayload::assoc($resNever['exercise'] ?? null);
        $neverBreakdown = $this->firstBreakdown($resNever);
        $this->assertArrayNotHasKey('_answerKey', $neverEx);
        $this->assertArrayNotHasKey('_explanations', $neverEx);
        $this->assertArrayNotHasKey('expected', $neverBreakdown);
        $this->assertArrayNotHasKey('explanation', $neverBreakdown);

        // 2. Author sees answers even when policy is 'never'
        $resAuthor = $makeEngineForAttempt('EVALUATED', 'never', null)->getAttempt($author, self::ORG_ID, self::ATTEMPT_ID);
        $authorEx = ActivityPayload::assoc($resAuthor['exercise'] ?? null);
        $authorBreakdown = $this->firstBreakdown($resAuthor);
        $this->assertArrayHasKey('_answerKey', $authorEx);
        $this->assertSame('8', $authorBreakdown['expected']);

        // 3. 'after_grading' hides during PENDING_REVIEW, reveals when EVALUATED
        $resPending = $makeEngineForAttempt('PENDING_REVIEW', 'after_grading', null)->getAttempt($student, self::ORG_ID, self::ATTEMPT_ID);
        $this->assertArrayNotHasKey('_answerKey', ActivityPayload::assoc($resPending['exercise'] ?? null));
        $resGraded = $makeEngineForAttempt('EVALUATED', 'after_grading', null)->getAttempt($student, self::ORG_ID, self::ATTEMPT_ID);
        $this->assertArrayHasKey('_answerKey', ActivityPayload::assoc($resGraded['exercise'] ?? null));

        // 4. 'immediate' reveals once SUBMITTED
        $resImmediate = $makeEngineForAttempt('SUBMITTED', 'immediate', null)->getAttempt($student, self::ORG_ID, self::ATTEMPT_ID);
        $this->assertArrayHasKey('_answerKey', ActivityPayload::assoc($resImmediate['exercise'] ?? null));

        // 5. 'deadline' hides before due_at, reveals after due_at
        $resBeforeDue = $makeEngineForAttempt('EVALUATED', 'deadline', gmdate(DATE_ATOM, time() + 3600))->getAttempt($student, self::ORG_ID, self::ATTEMPT_ID);
        $this->assertArrayNotHasKey('_answerKey', ActivityPayload::assoc($resBeforeDue['exercise'] ?? null));
        $beforeBreakdown = $this->firstBreakdown($resBeforeDue);
        $this->assertArrayNotHasKey('expected', $beforeBreakdown);

        $resAfterDue = $makeEngineForAttempt('EVALUATED', 'deadline', gmdate(DATE_ATOM, time() - 3600))->getAttempt($student, self::ORG_ID, self::ATTEMPT_ID);
        $this->assertArrayHasKey('_answerKey', ActivityPayload::assoc($resAfterDue['exercise'] ?? null));
    }

    public function testGate02ReviewAttemptRequiresRawScoreUsesVersionPassingScoreAndRecordsHistory(): void
    {
        $teacher = $this->identity(self::TEACHER_ACCOUNT_ID, self::TEACHER_PERSON_ID);
        $policy = $this->createMock(ActivityDomainPolicyInterface::class);
        $policy->method('canAccessParticipantAttempt')->willReturn(true);

        $executedSql = [];
        $db = $this->makeRoutingDb(
            oneHandler: static function (string $sql): ?array {
                if (str_contains($sql, 'FROM activity.attempts a')) {
                    return [
                        'id' => self::ATTEMPT_ID,
                        'activityId' => self::ACTIVITY_ID,
                        'versionId' => self::VERSION_PINNED_ID,
                        'assignmentId' => self::ASSIGNMENT_ID,
                        'participantId' => self::STUDENT_PERSON_ID,
                        'participantPersonId' => self::STUDENT_PERSON_ID,
                        'state' => 'PENDING_REVIEW',
                        'revision' => 2,
                        'seed' => 1,
                        'prevScore' => 5.0,
                        'maxScore' => 10.0,
                        'prevPassed' => false,
                        'rawScore' => 7.0,
                        'percentage' => 70.0,
                        'passed' => false,
                        'cfgJson' => '{}',
                        'exJson' => '{}',
                        'ansJson' => '{}',
                        'telJson' => '{}',
                        'evJson' => '{"rawScore":5,"maxScore":10}',
                        'startedAt' => '2026-11-25T10:00:00Z',
                        'expiresAt' => null,
                        'submittedAt' => '2026-11-25T10:05:00Z',
                        'evaluatedAt' => '2026-11-25T10:06:00Z',
                        'reviewNotes' => 'Needs more detail',
                        'pluginCode' => 'platform-assessment',
                        'title' => 'Essay',
                        'activityTitle' => 'Essay',
                        'authorPersonId' => self::TEACHER_PERSON_ID,
                        'passingScorePercent' => 80,
                        'versionRevealPolicy' => 'after_grading',
                        'versionConfigJson' => '{}',
                        'assignmentDueAt' => null,
                    ];
                }
                return null;
            },
            executeHandler: static function (string $sql, array $params) use (&$executedSql): int {
                $executedSql[] = ['sql' => $sql, 'params' => $params];
                return 1;
            },
        );

        $engine = $this->makeEngine($db, [self::TEACHER_ACCOUNT_ID => ['activity.use', 'activity.review']], $policy);

        // Missing rawScore must fail with 422 raw_score_required
        try {
            $engine->reviewAttempt($teacher, self::ORG_ID, self::ATTEMPT_ID, ['reviewNotes' => 'Nice']);
            $this->fail('Expected raw_score_required');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('raw_score_required', $e->reason);
        }

        // 7/10 = 70% < version passingScorePercent 80% -> passed MUST be false (not hardcoded 60%!)
        $engine->reviewAttempt($teacher, self::ORG_ID, self::ATTEMPT_ID, [
            'rawScore' => 7,
            'reviewNotes' => 'Needs more detail',
        ]);

        $updateCall = null;
        $eventCall = null;
        foreach ($executedSql as $call) {
            if (str_contains($call['sql'], 'UPDATE activity.attempts')) {
                $updateCall = $call;
            }
            if (str_contains($call['sql'], 'INSERT INTO activity.attempt_events')) {
                $eventCall = $call;
            }
        }
        $this->assertNotNull($updateCall);
        $this->assertFalse($updateCall['params'][':passed']);
        $this->assertSame(70.0, $updateCall['params'][':pct']);

        $this->assertNotNull($eventCall);
        $meta = ActivityPayload::assoc(json_decode((string) $eventCall['params'][':meta'], true));
        $this->assertEquals(5.0, $meta['previousScore']);
        $this->assertEquals(7.0, $meta['newScore']);
        $this->assertFalse($meta['previousPassed']);
        $this->assertFalse($meta['newPassed']);
        $this->assertSame('Needs more detail', $meta['notes']);
    }

    public function testGate03ExpiredAttemptPersistsBeforeThrowingAndCasRevisionIsRequired(): void
    {
        $student = $this->identity(self::STUDENT_ACCOUNT_ID, self::STUDENT_PERSON_ID);
        $committedSql = [];
        $txCommitted = false;

        $db = $this->makeRoutingDb(
            oneHandler: static fn(string $sql): ?array => str_contains($sql, 'FROM activity.attempts')
                ? [
                    'state' => 'IN_PROGRESS',
                    'revision' => 2,
                    'expires_at' => '2020-01-01T00:00:00+00:00',
                    'activityId' => self::ACTIVITY_ID,
                    'cfgJson' => '{}',
                    'exJson' => '{}',
                    'ansJson' => '{}',
                    'telJson' => '{}',
                    'pluginCode' => 'platform-typing',
                    'authorPersonId' => self::TEACHER_PERSON_ID,
                    'title' => 'Typing',
                    'passingScorePercent' => 60,
                ]
                : null,
            executeHandler: static function (string $sql, array $params) use (&$committedSql): int {
                $committedSql[] = $sql;
                return 1;
            },
            txCallback: static function (callable $fn) use (&$txCommitted): mixed {
                $res = $fn();
                // Reached only if callback did NOT throw inside transaction (simulating real commit)
                $txCommitted = true;
                return $res;
            },
        );

        $engine = $this->makeEngine($db, [self::STUDENT_ACCOUNT_ID => ['activity.use']]);

        // 1. Missing revision on saveDraft & submitAttempt -> 422 revision_required
        try {
            $engine->saveDraft($student, self::ORG_ID, self::ATTEMPT_ID, ['answers' => []]);
            $this->fail('Expected revision_required');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('revision_required', $e->reason);
        }

        try {
            $engine->submitAttempt($student, self::ORG_ID, self::ATTEMPT_ID, ['submission' => []]);
            $this->fail('Expected revision_required');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('revision_required', $e->reason);
        }

        // 2. Expired saveDraft commits EXPIRED update & transition BEFORE throwing 409 attempt_expired
        try {
            $engine->saveDraft($student, self::ORG_ID, self::ATTEMPT_ID, ['answers' => ['x' => 1], 'revision' => 2]);
            $this->fail('Expected attempt_expired');
        } catch (HttpError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('attempt_expired', $e->reason);
            $this->assertTrue($txCommitted, 'Transaction must commit EXPIRED state before throwing attempt_expired');
        }

        $this->assertTrue(
            array_reduce($committedSql, static fn(bool $carry, string $s): bool => $carry || str_contains($s, "state = 'EXPIRED'"), false),
        );

        // 3. Expired submitAttempt commits EXPIRED update BEFORE throwing 409 attempt_expired
        $txCommitted = false;
        $committedSql = [];
        try {
            $engine->submitAttempt($student, self::ORG_ID, self::ATTEMPT_ID, ['submission' => ['x' => 1], 'revision' => 2]);
            $this->fail('Expected attempt_expired');
        } catch (HttpError $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('attempt_expired', $e->reason);
            $this->assertTrue($txCommitted, 'Transaction must commit EXPIRED state before throwing attempt_expired');
        }
    }

    public function testGate03StartAttemptSupportsResumeActiveWithoutCreatingDuplicate(): void
    {
        $student = $this->identity(self::STUDENT_ACCOUNT_ID, self::STUDENT_PERSON_ID);
        $state = new class {
            public int $insertCount = 0;
        };

        $policy = $this->createMock(ActivityDomainPolicyInterface::class);
        $policy->method('canAccessActivity')->willReturn(true);
        $policy->method('canStartOpenPractice')->willReturn(true);
        $policy->method('canAccessParticipantAttempt')->willReturn(true);

        $db = $this->makeRoutingDb(
            oneHandler: static function (string $sql): array|null {
                if (str_contains($sql, 'FROM activity.items i')) {
                    return [
                        'id' => self::ACTIVITY_ID,
                        'pluginCode' => 'platform-typing',
                        'pluginTitle' => 'Typing Arena 4.0',
                        'category' => 'typing',
                        'authorPersonId' => self::TEACHER_PERSON_ID,
                        'title' => 'Practice',
                        'description' => '',
                        'status' => 'PUBLISHED',
                        'visibility' => 'public_catalog',
                        'tagsJson' => '[]',
                        'activeVersionId' => self::VERSION_ACTIVE_ID,
                        'createdAt' => '2026-11-25T10:00:00Z',
                        'updatedAt' => '2026-11-25T10:00:00Z',
                        'versionNumber' => 1,
                        'instructions' => '',
                        'configJson' => '{}',
                        'contentJson' => '{}',
                        'timeLimitSeconds' => 60,
                        'maxAttempts' => 0,
                        'passingScorePercent' => 60,
                        'gradingPolicy' => 'highest',
                        'revealAnswersPolicy' => 'after_grading',
                    ];
                }
                if (str_contains($sql, 'FROM activity.versions')) {
                    return [
                        'id' => self::VERSION_ACTIVE_ID,
                        'configJson' => '{}',
                        'contentJson' => '{}',
                        'timeLimitSeconds' => 60,
                        'maxAttempts' => 0,
                        'passingScorePercent' => 60,
                    ];
                }
                if (str_contains($sql, 'FROM activity.attempts a')) {
                    return [
                        'id' => self::ATTEMPT_ID,
                        'activityId' => self::ACTIVITY_ID,
                        'versionId' => self::VERSION_ACTIVE_ID,
                        'assignmentId' => null,
                        'participantPersonId' => self::STUDENT_PERSON_ID,
                        'state' => 'IN_PROGRESS',
                        'revision' => 3,
                        'seed' => 99,
                        'cfgJson' => '{}',
                        'exJson' => '{"promptText":"hello"}',
                        'ansJson' => '{"typedText":"hel"}',
                        'telJson' => '{}',
                        'evJson' => '{}',
                        'rawScore' => null,
                        'maxScore' => null,
                        'percentage' => null,
                        'passed' => null,
                        'startedAt' => '2026-11-25T10:00:00Z',
                        'expiresAt' => '2099-01-01T00:00:00Z',
                        'submittedAt' => null,
                        'evaluatedAt' => null,
                        'reviewNotes' => null,
                        'pluginCode' => 'platform-typing',
                        'activityTitle' => 'Practice',
                        'authorPersonId' => self::TEACHER_PERSON_ID,
                        'versionRevealPolicy' => 'after_grading',
                        'versionConfigJson' => '{}',
                        'assignmentDueAt' => null,
                    ];
                }
                return null;
            },
            scalarHandler: static function (string $sql) use ($state): mixed {
                if (str_contains($sql, 'INSERT INTO activity.attempts')) {
                    $state->insertCount++;
                    return 'new-id';
                }
                if (str_contains($sql, "state = 'IN_PROGRESS'")) {
                    return self::ATTEMPT_ID;
                }
                return null;
            },
        );

        $engine = $this->makeEngine($db, [self::STUDENT_ACCOUNT_ID => ['activity.use']], $policy);
        $resumed = $engine->startAttempt($student, self::ORG_ID, self::ACTIVITY_ID, ['resumeActive' => true]);

        $this->assertSame(0, $state->insertCount, 'resumeActive must return existing IN_PROGRESS attempt without inserting');
        $this->assertSame(self::ATTEMPT_ID, $resumed['id']);
        $this->assertSame(3, $resumed['revision']);
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function firstBreakdown(array $attempt): array
    {
        $eval = ActivityPayload::assoc($attempt['evaluation'] ?? null);
        $bd = $eval['breakdown'] ?? null;
        if (!is_array($bd) || !isset($bd[0])) {
            return [];
        }
        return ActivityPayload::assoc($bd[0]);
    }

    private function identity(string $accountId, string $personId): AuthenticatedIdentity
    {
        return new AuthenticatedIdentity(
            accountId: $accountId,
            personId: $personId,
            sessionId: '01950000-0000-7000-8000-000000000999',
            roles: [],
            permissions: [],
            locale: 'en',
            timezone: 'UTC',
        );
    }

    /** @param array<string, list<string>> $accountPerms */
    private function makePermissionService(array $accountPerms): PermissionService
    {
        $roles = $this->createMock(RoleRepository::class);
        $roles->method('accountHasRoleCode')->willReturn(false);
        $roles->method('findByAccountId')->willReturn([]);

        $permRepo = $this->createMock(PermissionRepository::class);
        $permRepo->method('accountHasPermission')->willReturnCallback(
            static fn(string $accountId, string $code): bool => in_array($code, $accountPerms[$accountId] ?? [], true),
        );

        return new PermissionService($roles, $permRepo);
    }

    /** @param array<string, list<string>> $accountPerms */
    private function makeEngine(
        ConnectionInterface $db,
        array $accountPerms,
        ?ActivityDomainPolicyInterface $policy = null,
    ): ActivityEngineService {
        $orgs = $this->createMock(OrganizationRepository::class);
        $orgs->method('activeMember')->willReturn(true);

        $perms = $this->makePermissionService($accountPerms);
        $redis = $this->createMock(RedisClientInterface::class);
        $authz = $this->createMock(RealtimeChannelAuthorizerInterface::class);
        $realtime = new RealtimeService($db, $redis, $authz, 'test-secret');
        $notifications = new NotificationsService($db, $orgs, $perms, $realtime);

        return new ActivityEngineService(
            $db,
            $orgs,
            $perms,
            new ActivityPluginRegistry(),
            $realtime,
            $notifications,
            $policy,
        );
    }

    /**
     * @param null|callable(string, array<string, mixed>): (?array<string, mixed>) $oneHandler
     * @param null|callable(string, array<string, mixed>): mixed $scalarHandler
     * @param null|callable(string, array<string, mixed>): list<array<string, mixed>> $allHandler
     * @param null|callable(string, array<string, mixed>): int $executeHandler
     * @param null|callable(callable(): mixed): mixed $txCallback
     */
    private function makeRoutingDb(
        ?callable $oneHandler = null,
        ?callable $scalarHandler = null,
        ?callable $allHandler = null,
        ?callable $executeHandler = null,
        ?callable $txCallback = null,
    ): ConnectionInterface {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('transaction')->willReturnCallback(
            $txCallback ?? static fn(callable $cb): mixed => $cb(),
        );

        $db->method('createCommand')->willReturnCallback(
            function (?string $sql = null, array $params = []) use ($oneHandler, $scalarHandler, $allHandler, $executeHandler): CommandInterface {
                $sqlStr = $sql ?? '';
                /** @var array<string, mixed> $boundParams */
                $boundParams = $params;
                $cmd = $this->createMock(CommandInterface::class);
                $cmd->method('queryOne')->willReturnCallback(
                    static function () use ($sqlStr, $boundParams, $oneHandler): ?array {
                        if ($oneHandler !== null) {
                            $res = $oneHandler($sqlStr, $boundParams);
                            if ($res !== null) {
                                return $res;
                            }
                        }
                        if (str_contains($sqlStr, 'INSERT INTO realtime.outbox')) {
                            return [
                                'seq' => 1,
                                'eventId' => self::ATTEMPT_ID,
                                'organizationId' => self::ORG_ID,
                                'channel' => (string) ($boundParams[':channel'] ?? 'user:test'),
                                'eventType' => (string) ($boundParams[':eventType'] ?? 'activity.event'),
                                'aggregateType' => (string) ($boundParams[':aggType'] ?? 'activity_attempt'),
                                'aggregateId' => (string) ($boundParams[':aggId'] ?? self::ATTEMPT_ID),
                                'actorAccountId' => $boundParams[':actorAccount'] ?? null,
                                'actorPersonId' => $boundParams[':actorPerson'] ?? null,
                                'payloadJson' => (string) ($boundParams[':payload'] ?? '{}'),
                                'targetsJson' => $boundParams[':targets'] ?? null,
                                'createdAt' => '2026-11-25T10:00:00Z',
                            ];
                        }
                        return null;
                    },
                );
                $cmd->method('queryScalar')->willReturnCallback(
                    static fn() => $scalarHandler !== null ? $scalarHandler($sqlStr, $boundParams) : false,
                );
                $cmd->method('queryAll')->willReturnCallback(
                    static fn() => $allHandler !== null ? $allHandler($sqlStr, $boundParams) : [],
                );
                $cmd->method('queryColumn')->willReturnCallback(
                    static fn() => [],
                );
                $cmd->method('execute')->willReturnCallback(
                    static fn() => $executeHandler !== null ? $executeHandler($sqlStr, $boundParams) : 1,
                );
                return $cmd;
            },
        );

        return $db;
    }
}
