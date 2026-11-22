<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Core\Time\Application\ClockInterface;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\EducationService;
use App\Education\Application\EducationStore;
use App\Education\Application\FileStore;
use App\Education\Application\Mutation;
use App\Education\Application\Scope;
use App\Environment;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

final class StudentPortalTest extends TestCase
{
    private ConnectionInterface $db;
    private ContainerInterface $container;
    private TransactionInterface $transaction;
    private EducationAccess $access;
    private EducationStore $store;
    private EducationService $service;
    private AuthenticatedIdentity $teacher;
    private Scope $teacherScope;
    private string $organization;

    protected function setUp(): void
    {
        self::assertSame('1', getenv('EDUCATION_ENABLED'), 'Run the dedicated Education suite with its module enabled.');
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $this->container = $container;
        $this->db = $container->get(ConnectionInterface::class);
        TestRuntimeGuard::assertDestructiveOperationsAllowed($this->db);
        $this->transaction = $this->db->beginTransaction();
        $this->db->createCommand("UPDATE core.modules SET enabled=true,enabled_at=now(),disabled_at=NULL WHERE code='education'")->execute();
        $this->store = $container->get(EducationStore::class);
        $this->access = $container->get(EducationAccess::class);
        $this->service = new EducationService(
            $this->store,
            $this->access,
            $this->fixedClock(),
            $container->get(FileStore::class),
            $container->get(Mutation::class),
            $container->get(PermissionService::class),
            $container->get(OrganizationRepository::class),
        );
        $this->organization = (string) $this->db->createCommand("INSERT INTO core.organizations(code,name)
            VALUES(:code,'Portal test') RETURNING id::text", [':code' => 'portal.test.' . bin2hex(random_bytes(8))])->queryScalar();
        $teacherPerson = $this->person('Portal teacher');
        $this->member($teacherPerson);
        $account = $this->account($teacherPerson, 'Portal teacher', 'education.teacher');
        $this->store->grantTeacher($this->organization, $account, $teacherPerson);
        $this->teacher = new AuthenticatedIdentity($account, $teacherPerson, $this->id(), ['education.teacher'], [], 'en-US', 'America/New_York');
        $this->teacherScope = $this->access->scope($this->teacher, $this->organization, 'education.people.manage');
    }

    protected function tearDown(): void
    {
        $this->transaction->rollBack();
    }

    public function testStudentActivatesByInvitationAndUsesOwnScopeOnly(): void
    {
        $group = $this->service->write('create_group', $this->teacherScope, ['name' => 'Portal group']);
        $person = $this->person('Portal student');
        $this->member($person);
        $student = $this->service->write('enroll_student', $this->teacherScope, ['groupId' => $group['id'], 'personId' => $person]);

        $account = $this->account($person, 'Portal student');
        $identity = new AuthenticatedIdentity($account, $person, $this->id(), ['education.student'], [], 'ru-RU', 'Europe/Moscow');
        $this->assertError(403, fn() => $this->access->studentScope($identity, $this->organization, 'education.student.use'));

        $invite = $this->service->write('invite_student', $this->teacherScope, [], $student['id']);
        self::assertNotEmpty($invite['code']);
        $card = $this->service->read('student', $this->teacherScope, [], $student['id']);
        self::assertNull($card['account']);

        $activated = $this->service->activateInvite($identity, $person, ['code' => $invite['code']]);
        self::assertSame($student['id'], $activated['studentId']);
        $this->assertError(409, fn() => $this->service->activateInvite($identity, $person, ['code' => $invite['code']]), 'invite_invalid');

        $scope = $this->access->studentScope($identity, $this->organization, 'education.student.use');
        self::assertSame($student['id'], $scope->studentId);
        $context = $this->access->context($identity);
        self::assertSame(
            [[ 'id' => $this->organization, 'student' => true, 'teacher' => false ]],
            array_map(static fn(array $row): array => ['id' => $row['id'], 'student' => $row['student'], 'teacher' => $row['teacher']], $context['organizations']),
        );
        $card = $this->service->read('student', $this->teacherScope, [], $student['id']);
        self::assertSame($account, $card['account']['accountId']);

        $this->service->write('revoke_student_account', $this->teacherScope, [], $student['id']);
        $this->assertError(403, fn() => $this->access->studentScope($identity, $this->organization, 'education.student.use'));
    }

    public function testInviteIsPersonBoundSingleUseAndExpiring(): void
    {
        $group = $this->service->write('create_group', $this->teacherScope, ['name' => 'Invite group']);
        $person = $this->person('Invite student');
        $this->member($person);
        $student = $this->service->write('enroll_student', $this->teacherScope, ['groupId' => $group['id'], 'personId' => $person]);
        $invite = $this->service->write('invite_student', $this->teacherScope, [], $student['id']);

        $stranger = $this->person('Stranger');
        $this->member($stranger);
        $strangerAccount = $this->account($stranger, 'Stranger');
        $strangerIdentity = new AuthenticatedIdentity($strangerAccount, $stranger, $this->id(), ['education.student'], [], 'en-US', 'UTC');
        $this->assertError(404, fn() => $this->service->activateInvite($strangerIdentity, $stranger, ['code' => $invite['code']]));

        $account = $this->account($person, 'Invite student');
        $identity = new AuthenticatedIdentity($account, $person, $this->id(), ['education.student'], [], 'ru-RU', 'UTC');
        $this->service->activateInvite($identity, $person, ['code' => $invite['code']]);
        $this->assertError(409, fn() => $this->service->activateInvite($identity, $person, ['code' => $invite['code']]), 'invite_invalid');
        $this->assertError(404, fn() => $this->service->activateInvite($identity, $person, ['code' => str_repeat('0', 24)]));

        $second = $this->service->write('invite_student', $this->teacherScope, [], $student['id']);
        $this->db->createCommand("UPDATE education.student_invites SET expires_at=now()-interval '1 minute'
            WHERE code_hash=:hash", [':hash' => hash('sha256', $second['code'])])->execute();
        $this->assertError(409, fn() => $this->service->activateInvite($identity, $person, ['code' => $second['code']]), 'invite_invalid');
        $third = $this->service->write('invite_student', $this->teacherScope, [], $student['id']);
        $this->assertError(409, fn() => $this->service->activateInvite($identity, $person, ['code' => $third['code']]), 'account_already_linked');
    }

    public function testStudentHomeDiaryAndLessonIsolation(): void
    {
        $group = $this->service->write('create_group', $this->teacherScope, ['name' => 'Diary group']);
        $first = $this->person('Diary first');
        $second = $this->person('Diary second');
        $this->member($first);
        $this->member($second);
        $firstStudent = $this->service->write('enroll_student', $this->teacherScope, ['groupId' => $group['id'], 'personId' => $first]);
        $secondStudent = $this->service->write('enroll_student', $this->teacherScope, ['groupId' => $group['id'], 'personId' => $second]);
        $lesson = $this->service->write('create_lesson', $this->teacherScope, ['groupId' => $group['id'], 'title' => 'Diary lesson',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z']);
        $this->service->write('complete_lesson', $this->teacherScope, [], $lesson['id']);
        $this->service->write('mark_attendance', $this->teacherScope, ['status' => 'present'], $lesson['id'], $firstStudent['id']);
        $this->service->write('save_lesson_result', $this->teacherScope, ['status' => 'mastered', 'comment' => 'Well done'], $lesson['id'], $firstStudent['id']);

        $account = $this->account($first, 'Diary first');
        $identity = new AuthenticatedIdentity($account, $first, $this->id(), ['education.student'], [], 'ru-RU', 'Europe/Moscow');
        $invite = $this->service->write('invite_student', $this->teacherScope, [], $firstStudent['id']);
        $this->service->activateInvite($identity, $first, ['code' => $invite['code']]);
        $scope = $this->access->studentScope($identity, $this->organization, 'education.student.use');

        $home = $this->service->read('student_home', $scope, []);
        self::assertSame('Diary group', $home['groups'][0]['name']);
        self::assertNull($home['nextLesson']);
        self::assertSame('mastered', $home['recentResults'][0]['resultStatus']);
        self::assertSame('platform-typing', $home['activities'][0]['code']);

        $history = $this->service->read('student_lessons', $scope, []);
        self::assertSame([$lesson['id']], array_column($history['items'], 'id'));
        self::assertSame('present', $history['items'][0]['attendance']);
        self::assertSame('mastered', $history['items'][0]['resultStatus']);

        $detail = $this->service->read('student_lesson', $scope, [], $lesson['id']);
        self::assertSame('Well done', $detail['resultComment']);
        self::assertSame([], $detail['materials']);

        $otherAccount = $this->account($second, 'Diary second');
        $otherIdentity = new AuthenticatedIdentity($otherAccount, $second, $this->id(), ['education.student'], [], 'en-US', 'UTC');
        $otherInvite = $this->service->write('invite_student', $this->teacherScope, [], $secondStudent['id']);
        $this->service->activateInvite($otherIdentity, $second, ['code' => $otherInvite['code']]);
        $otherScope = $this->access->studentScope($otherIdentity, $this->organization, 'education.student.use');
        $otherLessons = $this->service->read('student_lessons', $otherScope, [])['items'];
        self::assertSame([$lesson['id']], array_column($otherLessons, 'id'));
        self::assertSame('pending', $otherLessons[0]['resultStatus']);
        self::assertSame('pending', $otherLessons[0]['attendance']);
        self::assertNull($otherLessons[0]['resultComment']);
    }

    public function testTypingAttemptLifecycleMetricsAndIdempotency(): void
    {
        $group = $this->service->write('create_group', $this->teacherScope, ['name' => 'Typing group']);
        $person = $this->person('Typing student');
        $this->member($person);
        $student = $this->service->write('enroll_student', $this->teacherScope, ['groupId' => $group['id'], 'personId' => $person]);
        $account = $this->account($person, 'Typing student');
        $identity = new AuthenticatedIdentity($account, $person, $this->id(), ['education.student'], [], 'ru-RU', 'UTC');
        $invite = $this->service->write('invite_student', $this->teacherScope, [], $student['id']);
        $this->service->activateInvite($identity, $person, ['code' => $invite['code']]);
        $scope = $this->access->studentScope($identity, $this->organization, 'education.activities.use');

        $catalog = $this->service->read('student_activity_catalog', $scope, []);
        self::assertSame('platform-typing', $catalog['items'][0]['code']);
        $config = $this->service->read('student_activity_config', $scope, [], 'platform-typing');
        self::assertSame([15, 30, 60], $config['config']['durations']);

        $started = $this->service->write('student_start_attempt', $scope, ['durationSeconds' => 15, 'language' => 'en'], 'platform-typing');
        $text = $started['exercise']['text'];
        self::assertNotSame('', $text);
        self::assertSame(15, $started['exercise']['durationSeconds']);
        $attemptId = $started['attempt']['id'];
        $this->assertError(422, fn() => $this->service->write('student_start_attempt', $scope, ['durationSeconds' => 5, 'language' => 'en'], 'platform-typing'));

        $finished = $this->service->write('student_finish_attempt', $scope, ['typed' => $text, 'durationSeconds' => 15], $attemptId);
        $expectedWpm = round(strlen($text) / 5 / 0.25, 1);
        self::assertSame($expectedWpm, $finished['wpm']);
        self::assertSame(100.0, $finished['accuracy']);
        self::assertSame(0, $finished['errors']);
        $again = $this->service->write('student_finish_attempt', $scope, ['typed' => $text, 'durationSeconds' => 15], $attemptId);
        self::assertSame($attemptId, $again['attemptId']);
        $this->assertError(409, fn() => $this->service->write('student_finish_attempt', $scope, ['typed' => $text . 'x', 'durationSeconds' => 15], $attemptId));
        $this->assertError(422, fn() => $this->service->write('student_finish_attempt', $scope, ['typed' => $text, 'durationSeconds' => 30], $attemptId));

        $typedWithError = substr($text, 0, 10) . (substr($text, 10, 1) === 'a' ? 'b' : 'a') . substr($text, 11);
        $secondAttempt = $this->service->write('student_start_attempt', $scope, ['durationSeconds' => 15, 'language' => 'en'], 'platform-typing');
        $secondResult = $this->service->write('student_finish_attempt', $scope, ['typed' => $typedWithError, 'durationSeconds' => 15], $secondAttempt['attempt']['id']);
        self::assertGreaterThan(0, $secondResult['errors']);
        self::assertLessThan(100.0, $secondResult['accuracy']);

        $history = $this->service->read('student_typing_history', $scope, []);
        self::assertSame($attemptId, $history['best']['attemptId']);
        $attempts = $this->service->read('student_attempt_history', $scope, []);
        self::assertCount(2, $attempts['items']);

        $stranger = $this->person('Typing stranger');
        $this->member($stranger);
        $strangerAccount = $this->account($stranger, 'Typing stranger');
        $strangerIdentity = new AuthenticatedIdentity($strangerAccount, $stranger, $this->id(), ['education.student'], [], 'en-US', 'UTC');
        $strangerStudent = $this->service->write('enroll_student', $this->teacherScope, ['groupId' => $group['id'], 'personId' => $stranger]);
        $strangerInvite = $this->service->write('invite_student', $this->teacherScope, [], $strangerStudent['id']);
        $this->service->activateInvite($strangerIdentity, $stranger, ['code' => $strangerInvite['code']]);
        $strangerScope = $this->access->studentScope($strangerIdentity, $this->organization, 'education.activities.use');
        $this->assertError(404, fn() => $this->service->write('student_finish_attempt', $strangerScope, ['typed' => $text, 'durationSeconds' => 15], $attemptId));
    }

    public function testTeacherAssignsActivityAndReadsOnlyOwnGroupResults(): void
    {
        $group = $this->service->write('create_group', $this->teacherScope, ['name' => 'Assign group']);
        $person = $this->person('Assign student');
        $this->member($person);
        $student = $this->service->write('enroll_student', $this->teacherScope, ['groupId' => $group['id'], 'personId' => $person]);
        $assignment = $this->service->write(
            'activities_assign',
            $this->teacherScope,
            ['groupId' => $group['id'], 'config' => ['durationSeconds' => 30]],
            'platform-typing',
        );
        self::assertSame('platform-typing', $assignment['activityCode']);
        $this->assertError(422, fn() => $this->service->write(
            'activities_assign',
            $this->teacherScope,
            ['config' => []],
            'platform-typing',
        ));
        $this->assertError(422, fn() => $this->service->write(
            'activities_assign',
            $this->teacherScope,
            ['groupId' => $group['id'], 'config' => ['durationSeconds' => 5]],
            'platform-typing',
        ));

        $account = $this->account($person, 'Assign student');
        $identity = new AuthenticatedIdentity($account, $person, $this->id(), ['education.student'], [], 'en-US', 'UTC');
        $invite = $this->service->write('invite_student', $this->teacherScope, [], $student['id']);
        $this->service->activateInvite($identity, $person, ['code' => $invite['code']]);
        $scope = $this->access->studentScope($identity, $this->organization, 'education.activities.use');
        $config = $this->service->read('student_activity_config', $scope, [], 'platform-typing');
        self::assertSame($assignment['id'], $config['assignments'][0]['id']);
        self::assertSame(['durationSeconds' => 30], $config['assignments'][0]['config']);
        $teacherAssignments = $this->service->read('activity_assignments', $this->teacherScope, []);
        self::assertSame($assignment['id'], $teacherAssignments['items'][0]['id']);
        self::assertSame(['durationSeconds' => 30], $teacherAssignments['items'][0]['config']);
        self::assertSame('en', $config['assignments'][0]['effectiveConfig']['language']);

        $started = $this->service->write('student_start_attempt', $scope, ['durationSeconds' => 30, 'language' => 'en'], 'platform-typing');
        $this->service->write('student_finish_attempt', $scope, ['typed' => $started['exercise']['text'], 'durationSeconds' => 30], $started['attempt']['id']);

        $results = $this->service->read('group_activity_results', $this->teacherScope, ['activity' => 'platform-typing'], $group['id']);
        self::assertSame('Assign student Test', $results['items'][0]['name']);
        self::assertSame(100.0, $results['items'][0]['accuracy']);

        $otherTeacherPerson = $this->person('Assign other');
        $this->member($otherTeacherPerson);
        $otherAccount = $this->account($otherTeacherPerson, 'Assign other', 'education.teacher');
        $this->store->grantTeacher($this->organization, $otherAccount, $otherTeacherPerson);
        $otherIdentity = new AuthenticatedIdentity($otherAccount, $otherTeacherPerson, $this->id(), ['education.teacher'], [], 'en-US', 'UTC');
        $otherScope = $this->access->scope($otherIdentity, $this->organization, 'education.activities.manage');
        $this->assertError(404, fn() => $this->service->read('group_activity_results', $otherScope, ['activity' => 'platform-typing'], $group['id']));
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-03-08T14:00:00Z');
            }
        };
    }

    private function person(string $name): string
    {
        return (string) $this->db->createCommand("INSERT INTO core.persons(given_name,family_name) VALUES(:name,'Test') RETURNING id::text", [':name' => $name])->queryScalar();
    }

    private function member(string $person): void
    {
        $this->db->createCommand(
            'INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))',
            [':org' => $this->organization, ':person' => $person],
        )->execute();
    }

    private function account(string $person, string $label, string $role = 'education.student'): string
    {
        $account = (string) $this->db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label)
            VALUES(CAST(:person AS uuid),'human','active',:label) RETURNING id::text", [':person' => $person, ':label' => $label])->queryScalar();
        $this->db->createCommand("INSERT INTO core.account_roles(account_id,role_id)
            SELECT CAST(:account AS uuid),id FROM core.roles WHERE code=:role", [':account' => $account, ':role' => $role])->execute();
        return $account;
    }

    private function id(): string
    {
        return (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
    }

    private function assertError(int $status, callable $action, ?string $reason = null): void
    {
        try {
            $action();
            self::fail('Expected Education request rejection.');
        } catch (EducationError $error) {
            self::assertSame($status, $error->status);
            if ($reason !== null) {
                self::assertSame($reason, $error->reason);
            }
        }
    }
}
