<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Education\Application\CurriculumService;
use App\Education\Application\CurriculumStore;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\EducationService;
use App\Education\Application\EducationStore;
use App\Education\Application\Scope;
use App\Environment;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

/** Real PostgreSQL integration, rollback only, guarded isolated test database. */
final class CurriculumFoundationTest extends TestCase
{
    private ConnectionInterface $db;
    private ContainerInterface $container;
    private TransactionInterface $transaction;
    private CurriculumService $curriculum;
    private CurriculumStore $catalog;
    private EducationService $education;
    private EducationStore $store;
    private Scope $teacher;
    private Scope $colleague;
    private Scope $outsider;
    private array $group;
    private array $program;
    private array $module;
    private array $entry;

    protected function setUp(): void
    {
        $this->container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $this->db = $this->container->get(ConnectionInterface::class);
        TestRuntimeGuard::assertDestructiveOperationsAllowed($this->db);
        $this->transaction = $this->db->beginTransaction();
        $this->db->createCommand("UPDATE core.modules SET enabled=true,enabled_at=now(),disabled_at=NULL WHERE code='education'")->execute();
        $this->curriculum = $this->container->get(CurriculumService::class);
        $this->catalog = $this->container->get(CurriculumStore::class);
        $this->education = $this->container->get(EducationService::class);
        $this->store = $this->container->get(EducationStore::class);
        $org = $this->organization();
        $this->teacher = $this->teacher($org);
        $this->colleague = $this->teacher($org);
        $this->outsider = $this->teacher($this->organization());
        $this->group = $this->education->write('create_group', $this->teacher, ['name' => 'Curriculum group']);
        $this->program = $this->curriculum->write('create_program', $this->teacher, ['name' => 'Programming', 'description' => 'Original program']);
        $this->module = $this->curriculum->write(
            'create_curriculum_module',
            $this->teacher,
            ['name' => 'Foundations', 'description' => '', 'position' => 2],
            $this->program['id'],
        );
        $this->entry = $this->curriculum->write(
            'create_curriculum_lesson',
            $this->teacher,
            ['title' => 'Variables', 'description' => 'Original lesson', 'position' => 1],
            $this->module['id'],
        );
    }

    protected function tearDown(): void
    {
        $this->transaction->rollBack();
    }

    public function testAssignmentSnapshotsSurviveEditsArchivalAndReassignment(): void
    {
        $first = $this->assign();
        $this->curriculum->write(
            'update_curriculum_lesson',
            $this->teacher,
            ['title' => 'Renamed', 'description' => 'Changed content', 'position' => 1, 'version' => 1],
            $this->entry['id'],
        );
        $saved = $this->curriculum->read('group_programs', $this->teacher, $this->group['id'])['items'][0];
        self::assertSame('Variables', $saved['modules'][0]['lessons'][0]['title']);
        self::assertSame('Original lesson', $saved['modules'][0]['lessons'][0]['description']);
        $second = $this->assign();
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame('Renamed', $second['modules'][0]['lessons'][0]['title']);
        $history = $this->curriculum->read('group_programs', $this->teacher, $this->group['id'])['items'];
        self::assertCount(2, $history);
        self::assertNotNull($history[1]['endedAt']);
        $this->curriculum->write(
            'update_program',
            $this->teacher,
            ['name' => 'Programming', 'description' => 'Archived catalog', 'archived' => true],
            $this->program['id'],
        );
        self::assertSame('Renamed', $this->curriculum->read('group_programs', $this->teacher, $this->group['id'])['items'][0]['modules'][0]['lessons'][0]['title']);
        $this->error(409, fn() => $this->assign(), 'program_archived');
        self::assertSame(2, (int) $this->db->createCommand(
            'SELECT count(*) FROM education.curriculum_revisions WHERE organization_id=CAST(:org AS uuid)',
            [':org' => $this->teacher->organizationId],
        )->queryScalar());
    }

    public function testResultsDriveStudentProgressAndRepeatedSessionsCountOnce(): void
    {
        $this->assign();
        $student = $this->student();
        $lesson = $this->lesson('2001-01-01');
        $this->education->write('mark_attendance', $this->teacher, ['status' => 'present'], $lesson['id'], $student['id']);
        $this->education->write('complete_lesson', $this->teacher, [], $lesson['id']);
        $this->education->write('save_lesson_result', $this->teacher, ['status' => 'developing', 'comment' => null], $lesson['id'], $student['id']);
        self::assertSame(100, $this->curriculum->read('group_progress', $this->teacher, $this->group['id'])['programs'][0]['percent']);
        self::assertSame(0, $this->curriculum->read('student_curriculum_progress', $this->teacher, $student['id'])['programs'][0]['percent']);
        $this->education->write('save_lesson_result', $this->teacher, ['status' => 'mastered', 'comment' => 'Understood'], $lesson['id'], $student['id']);
        $repeat = $this->lesson('2001-01-02');
        $this->education->write('complete_lesson', $this->teacher, [], $repeat['id']);
        $progress = $this->curriculum->read('student_curriculum_progress', $this->teacher, $student['id'])['programs'][0];
        self::assertSame(1, $progress['completedLessons']);
        self::assertSame(100, $progress['percent']);
        $own = new Scope($this->teacher->organizationId, $this->id(), $student['personId'], 'en-US', 'UTC', $student['id']);
        self::assertSame(100, $this->curriculum->read('own_curriculum_progress', $own, null)['programs'][0]['percent']);
        $this->education->write('save_lesson_result', $this->teacher, ['status' => 'needs_support', 'comment' => null], $lesson['id'], $student['id']);
        self::assertSame(0, $this->curriculum->read('student_curriculum_progress', $this->teacher, $student['id'])['programs'][0]['percent']);
    }

    public function testExplicitTeachersCanCollaborateWithoutSeeingOtherGroups(): void
    {
        $private = $this->education->write('create_group', $this->teacher, ['name' => 'Unassigned group']);
        $this->assign();
        $student = $this->student();
        self::assertNull($this->store->group($this->colleague, $this->group['id']));
        $this->curriculum->write('set_group_teachers', $this->teacher, ['accountIds' => [$this->colleague->accountId]], $this->group['id']);
        self::assertNotNull($this->store->group($this->colleague, $this->group['id']));
        self::assertNull($this->store->group($this->colleague, $private['id']));
        $newStudent = $this->curriculum->write(
            'create_student',
            $this->colleague,
            ['givenName' => 'Shared', 'familyName' => 'Student', 'groupId' => $this->group['id']],
        );
        self::assertNotNull($this->store->student($this->teacher, $newStudent['id']));
        self::assertSame($this->teacher->accountId, $this->db->createCommand(
            'SELECT owner_account_id::text FROM education.students WHERE id=CAST(:id AS uuid)',
            [':id' => $newStudent['id']],
        )->queryScalar());
        $lesson = $this->education->write('create_lesson', $this->colleague, $this->lessonValues('2001-01-01'));
        $this->education->write('mark_attendance', $this->colleague, ['status' => 'present'], $lesson['id'], $student['id']);
        $this->education->write('complete_lesson', $this->colleague, [], $lesson['id']);
        $this->education->write('save_lesson_result', $this->colleague, ['status' => 'mastered'], $lesson['id'], $student['id']);
        self::assertSame(100, $this->curriculum->read('student_curriculum_progress', $this->colleague, $student['id'])['programs'][0]['percent']);
        $reviser = $this->db->createCommand('SELECT revised_by_account_id::text FROM education.result_revisions
            WHERE result_id IN (SELECT id FROM education.lesson_results WHERE lesson_id=CAST(:id AS uuid))', [':id' => $lesson['id']])->queryScalar();
        self::assertSame($this->colleague->accountId, $reviser);
        $this->error(403, fn() => $this->curriculum->write('set_group_teachers', $this->colleague, ['accountIds' => []], $this->group['id']));
        $this->error(404, fn() => $this->education->write('update_group', $this->colleague, ['name' => 'No'], $private['id']));
        $this->curriculum->write('set_group_teachers', $this->teacher, ['accountIds' => []], $this->group['id']);
        self::assertNull($this->store->lesson($this->colleague, $lesson['id']));
    }

    public function testCrossOrganizationBindingsAndStaleCatalogWritesAreRejected(): void
    {
        $this->error(404, fn() => $this->curriculum->read('program', $this->outsider, $this->program['id']));
        $this->error(404, fn() => $this->curriculum->write(
            'assign_program',
            $this->outsider,
            ['programId' => $this->program['id']],
            $this->group['id'],
        ));
        $this->error(409, fn() => $this->curriculum->write(
            'update_curriculum_lesson',
            $this->teacher,
            ['title' => 'Stale', 'position' => 1, 'version' => 99],
            $this->entry['id'],
        ), 'catalog_version_conflict');
        // Failed validation must roll back a new scheduled lesson as well.
        $this->error(422, fn() => $this->lesson('2001-01-01'), 'curriculum_lesson_not_assigned');
        self::assertSame([], $this->store->lessons($this->teacher, '2001-01-01T00:00:00Z', '2001-01-03T00:00:00Z'));
        $this->assign();
        $this->lesson('2001-01-01');
        self::assertCount(1, $this->store->lessons($this->teacher, '2001-01-01T00:00:00Z', '2001-01-03T00:00:00Z'));
    }

    public function testStudentCreationUsesCoreAndEnforcesExplicitDelegation(): void
    {
        $student = $this->student();
        self::assertTrue($this->container->get(\App\Core\Organization\Application\OrganizationRepository::class)
            ->activeMember($student['personId'], $this->teacher->organizationId));
        self::assertNotNull($this->container->get(\App\Core\Person\Application\GetPerson::class)->execute($student['personId']));
        $this->error(404, fn() => $this->curriculum->write(
            'create_student',
            $this->outsider,
            ['givenName' => 'Other', 'familyName' => 'Student', 'groupId' => $this->group['id']],
        ));
        $this->error(422, fn() => $this->curriculum->write(
            'create_student',
            $this->teacher,
            ['givenName' => 'Test', 'familyName' => 'Student', 'birthDate' => '3000-01-01', 'groupId' => $this->group['id']],
        ));
    }

    private function assign(): array
    {
        return $this->curriculum->write('assign_program', $this->teacher, ['programId' => $this->program['id']], $this->group['id']);
    }
    private function student(): array
    {
        return $this->curriculum->write(
            'create_student',
            $this->teacher,
            ['givenName' => 'New', 'familyName' => 'Student', 'groupId' => $this->group['id']],
        );
    }
    private function lesson(string $date): array
    {
        return $this->education->write('create_lesson', $this->teacher, $this->lessonValues($date));
    }
    private function lessonValues(string $date): array
    {
        return ['groupId' => $this->group['id'], 'title' => 'Variables', 'location' => '',
            'startsAt' => $date . 'T10:00:00Z', 'endsAt' => $date . 'T11:00:00Z', 'curriculumLessonId' => $this->entry['id']];
    }
    private function organization(): string
    {
        return (string) $this->db->createCommand(
            "INSERT INTO core.organizations(code,name) VALUES(:code,'Curriculum test') RETURNING id::text",
            [':code' => 'curriculum.' . bin2hex(random_bytes(8))],
        )->queryScalar();
    }
    private function teacher(string $org): Scope
    {
        $person = (string) $this->db->createCommand("INSERT INTO core.persons(given_name,family_name) VALUES('Curriculum','Teacher') RETURNING id::text")->queryScalar();
        $account = (string) $this->db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label)
            VALUES(CAST(:person AS uuid),'human','active','Curriculum teacher') RETURNING id::text", [':person' => $person])->queryScalar();
        $this->db->createCommand(
            'INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))',
            [':org' => $org, ':person' => $person],
        )->execute();
        $this->db->createCommand(
            "INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:id AS uuid),id FROM core.roles WHERE code='education.teacher'",
            [':id' => $account],
        )->execute();
        $this->store->grantTeacher($org, $account, $person);
        return $this->container->get(EducationAccess::class)->scope(new AuthenticatedIdentity($account, $person, $this->id(), [], [], 'en-US', 'UTC'), $org, 'education.access');
    }
    private function id(): string
    {
        return (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
    }
    private function error(int $status, callable $action, ?string $reason = null): void
    {
        try {
            $action();
            self::fail('Expected Education rejection.');
        } catch (EducationError $e) {
            self::assertSame($status, $e->status);
            if ($reason !== null) {
                self::assertSame($reason, $e->reason);
            }
        }
    }
}
