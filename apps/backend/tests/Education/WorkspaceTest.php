<?php

declare(strict_types=1);

namespace App\Tests\Education;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\PersonalDataErasureHandlerRegistry;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\DataCategoryDefinition;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Core\Time\Application\ClockInterface;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\EducationService;
use App\Education\Application\EducationStore;
use App\Education\Application\FileStore;
use App\Education\Application\Input;
use App\Education\Application\Mutation;
use App\Education\Application\Scope;
use App\Education\Infrastructure\LawfulProcessingBasisContributor;
use App\Education\Infrastructure\ProcessingRestrictionContributor;
use App\Education\Infrastructure\RetentionCandidateContributor;
use App\Education\Infrastructure\WorkspaceErasureHandler;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Environment;
use DateTimeImmutable;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

final class WorkspaceTest extends TestCase
{
    private ConnectionInterface $db;
    private ContainerInterface $container;
    private TransactionInterface $transaction;
    private EducationAccess $access;
    private EducationStore $store;
    private EducationService $service;
    private FileStore $files;
    private AuthenticatedIdentity $actor;
    private Scope $scope;
    private string $organization;
    /** @var list<string> */
    private array $storedFiles = [];

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
        $this->files = $container->get(FileStore::class);
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-03-08T14:00:00Z');
            }
        };
        $this->service = new EducationService($this->store, $this->access, $clock, $this->files,
            $container->get(Mutation::class), $container->get(PermissionService::class),
            $container->get(OrganizationRepository::class));
        $this->organization = (string) $this->db->createCommand("INSERT INTO core.organizations(code,name)
            VALUES(:code,'Education test') RETURNING id::text", [':code' => 'education.test.' . bin2hex(random_bytes(8))])->queryScalar();
        $this->actor = $this->teacher('First');
        $this->scope = $this->access->scope($this->actor, $this->organization, 'education.access');
    }

    protected function tearDown(): void
    {
        foreach ($this->storedFiles as $key) {
            $this->files->remove($key);
        }
        $this->transaction->rollBack();
    }

    public function testOrganizationDomainRoleAndCurrentPermissionsAreAllRequired(): void
    {
        self::assertCount(1, $this->access->context($this->actor)['organizations']);
        $other = $this->teacher('Other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.access');
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Private class']);
        self::assertNull($this->store->group($otherScope, $group['id']));
        $this->assertError(404, fn() => $this->service->read('group', $otherScope, [], $group['id']));
        $this->assertError(403, fn() => $this->access->scope($this->actor, $this->id(), 'education.access'));
        $this->db->createCommand('DELETE FROM education.teachers WHERE account_id=CAST(:id AS uuid)', [':id' => $other->accountId])->execute();
        $this->assertError(403, fn() => $this->access->scope($other, $this->organization, 'education.access'));
        $this->db->createCommand(
            "UPDATE core.organization_memberships SET status='left',left_at=now()
            WHERE organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid)",
            [':org' => $this->organization, ':person' => $this->actor->personId],
        )->execute();
        $this->assertError(403, fn() => $this->access->scope($this->actor, $this->organization, 'education.access'));
        $this->db->createCommand(
            "UPDATE core.organization_memberships SET status='active',left_at=NULL
            WHERE organization_id=CAST(:org AS uuid) AND person_id=CAST(:person AS uuid)",
            [':org' => $this->organization, ':person' => $this->actor->personId],
        )->execute();
        $this->db->createCommand('DELETE FROM core.account_roles WHERE account_id=CAST(:id AS uuid)', [':id' => $this->actor->accountId])->execute();
        $this->assertError(403, fn() => $this->access->scope($this->actor, $this->organization, 'education.access'));
        $this->db->createCommand("UPDATE core.modules SET enabled=false,disabled_at=now() WHERE code='education'")->execute();
        $this->assertError(404, fn() => $this->access->context($this->actor));
    }

    public function testRealLessonsTimelineAttendanceAndEmptyDay(): void
    {
        self::assertSame([], $this->service->read('timeline', $this->scope, ['date' => '2026-03-08'])['items']);
        self::assertNull($this->service->read('next', $this->scope, []));
        self::assertSame([], $this->service->read('attention', $this->scope, [])['items']);
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Python']);
        $studentPerson = $this->person('Student');
        $this->member($studentPerson);
        $student = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $studentPerson]);
        $outside = $this->person('Outside');
        $this->assertError(403, fn() => $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $outside]));
        $current = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Current',
            'startsAt' => '2026-03-08T13:30:00Z', 'endsAt' => '2026-03-08T15:00:00Z', 'location' => 'Room 1']);
        self::assertSame(1, $current['studentCount']);
        self::assertSame($current['id'], $this->service->read('current', $this->scope, [])['id']);
        $event = $this->service->write('create_event', $this->scope, ['title' => 'Call', 'kind' => 'meeting',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T13:20:00Z']);
        $timeline = $this->service->read('timeline', $this->scope, ['date' => '2026-03-08']);
        self::assertSame([$event['id'], $current['id']], array_column($timeline['items'], 'id'));
        $yesterday = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Yesterday',
            'startsAt' => '2026-03-07T13:30:00Z', 'endsAt' => '2026-03-07T15:00:00Z']);
        $this->service->write('mark_attendance', $this->scope, ['status' => 'absent'], $yesterday['id'], $student['id']);
        self::assertSame(1, $this->service->read('attention', $this->scope, [])['items'][0]['absentCount']);
        self::assertSame('Student Test', $this->service->read('participants', $this->scope, [], $current['id'])['items'][0]['name']);
        self::assertSame([], $this->service->read('timeline', $this->scope, ['date' => '2026-03-09'])['items']);
        $audit = $this->db->createCommand(
            "SELECT metadata::text FROM core.audit_events WHERE action='education.create_group' AND resource_id=:id",
            [':id' => $group['id']],
        )->queryScalar();
        self::assertIsString($audit);
        self::assertStringNotContainsString('Python', $audit);
    }

    public function testGroupCompositionRenameTransferRemovalAndArchivePreserveHistory(): void
    {
        $group = $this->service->write('create_group', $this->scope, ['name' => 'First group']);
        $target = $this->service->write('create_group', $this->scope, ['name' => 'Second group']);
        self::assertNull($group['archivedAt']);
        self::assertSame(0, $group['studentCount']);
        $firstPerson = $this->person('Group first');
        $secondPerson = $this->person('Group second');
        $this->member($firstPerson);
        $this->member($secondPerson);
        $student = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $firstPerson]);
        $duplicate = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $firstPerson]);
        self::assertSame($student['id'], $duplicate['id']);
        $composition = $this->service->read('group_students', $this->scope, [], $group['id']);
        self::assertCount(1, $composition['items']);
        self::assertSame('Group first Test', $composition['items'][0]['name']);
        self::assertSame(1, $this->service->read('group', $this->scope, [], $group['id'])['studentCount']);
        $renamed = $this->service->write('update_group', $this->scope, ['name' => 'Renamed group'], $group['id']);
        self::assertSame('Renamed group', $renamed['name']);
        $secondStudent = $this->service->write('enroll_student', $this->scope, ['groupId' => $target['id'], 'personId' => $secondPerson]);
        $this->service->write('transfer_student', $this->scope, ['groupId' => $target['id']], $group['id'], $student['id']);
        self::assertSame([], $this->service->read('group_students', $this->scope, [], $group['id'])['items']);
        $transferred = array_column($this->service->read('group_students', $this->scope, [], $target['id'])['items'], 'id');
        self::assertCount(2, $transferred);
        self::assertContains($student['id'], $transferred);
        $this->service->write('remove_group_student', $this->scope, [], $target['id'], $student['id']);
        self::assertSame([$secondStudent['id']], array_column($this->service->read('group_students', $this->scope, [], $target['id'])['items'], 'id'));
        self::assertContains($student['id'], array_column($this->service->read('students', $this->scope, [])['items'], 'id'));
        self::assertSame(1, (int) $this->db->createCommand(
            'SELECT count(*) FROM core.persons WHERE id=CAST(:person AS uuid)',
            [':person' => $firstPerson],
        )->queryScalar());
        $this->service->write('remove_group_student', $this->scope, [], $target['id'], $student['id']);

        $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $firstPerson]);
        $lesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Archived history',
            'startsAt' => '2026-03-08T13:30:00Z', 'endsAt' => '2026-03-08T15:00:00Z']);
        $this->assertError(409, fn() => $this->service->write('archive_group', $this->scope, [], $group['id']), 'group_has_scheduled_lessons');
        $this->service->write('complete_lesson', $this->scope, [], $lesson['id']);
        $this->service->write('mark_attendance', $this->scope, ['status' => 'present'], $lesson['id'], $student['id']);
        $archived = $this->service->write('archive_group', $this->scope, [], $group['id']);
        self::assertNotNull($archived['archivedAt']);
        self::assertSame($archived['archivedAt'], $this->service->write('archive_group', $this->scope, [], $group['id'])['archivedAt']);
        self::assertCount(1, $this->service->read('group_students', $this->scope, [], $group['id'])['items']);
        self::assertSame('completed', $this->service->read('lesson', $this->scope, [], $lesson['id'])['status']);
        self::assertSame('present', $this->service->read('participants', $this->scope, [], $lesson['id'])['items'][0]['status']);
        self::assertContains($group['id'], array_column($this->service->read('groups', $this->scope, [])['items'], 'id'));
        $this->assertError(409, fn() => $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'],
            'title' => 'Blocked', 'startsAt' => '2026-03-08T16:00:00Z', 'endsAt' => '2026-03-08T17:00:00Z']), 'group_archived');
        $this->assertError(409, fn() => $this->service->write('enroll_student', $this->scope, [
            'groupId' => $group['id'], 'personId' => $secondPerson,
        ]), 'group_archived');
        $this->assertError(409, fn() => $this->service->write('update_group', $this->scope, ['name' => 'Blocked'], $group['id']), 'group_archived');
        $this->assertError(409, fn() => $this->service->write('remove_group_student', $this->scope, [], $group['id'], $student['id']), 'group_archived');
        $this->assertError(409, fn() => $this->service->write('transfer_student', $this->scope, ['groupId' => $target['id']], $group['id'], $student['id']), 'group_archived');
        $this->assertError(409, fn() => $this->service->write('transfer_student', $this->scope, ['groupId' => $group['id']], $target['id'], $secondStudent['id']), 'group_archived');
    }

    public function testLessonEditingTransitionsOverlapAndAttendanceHistory(): void
    {
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Schedule group']);
        $otherGroup = $this->service->write('create_group', $this->scope, ['name' => 'Other schedule group']);
        $person = $this->person('Schedule first');
        $otherPerson = $this->person('Schedule second');
        $this->member($person);
        $this->member($otherPerson);
        $student = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $person]);
        $otherStudent = $this->service->write('enroll_student', $this->scope, ['groupId' => $otherGroup['id'], 'personId' => $otherPerson]);
        $lesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Original',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z', 'location' => 'Room A']);
        $this->assertError(409, fn() => $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'],
            'title' => 'Overlap', 'startsAt' => '2026-03-08T13:30:00Z', 'endsAt' => '2026-03-08T14:30:00Z']), 'schedule_conflict');
        $adjacent = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Adjacent',
            'startsAt' => '2026-03-08T14:00:00Z', 'endsAt' => '2026-03-08T15:00:00Z']);
        $this->service->write('create_event', $this->scope, ['title' => 'Overlapping event', 'kind' => 'meeting',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z']);
        $this->service->write('mark_attendance', $this->scope, ['status' => 'present'], $lesson['id'], $student['id']);
        $edited = $this->service->write('update_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Edited',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z', 'location' => 'Room B'], $lesson['id']);
        self::assertSame('Edited', $edited['title']);
        self::assertSame('Room B', $edited['location']);
        self::assertSame('present', $this->service->read('participants', $this->scope, [], $lesson['id'])['items'][0]['status']);
        $this->assertError(409, fn() => $this->service->write('update_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Edited',
            'startsAt' => '2026-03-08T13:30:00Z', 'endsAt' => '2026-03-08T14:30:00Z', 'location' => 'Room B'], $lesson['id']), 'schedule_conflict');
        $this->service->write('cancel_lesson', $this->scope, [], $adjacent['id']);
        self::assertSame('cancelled', $this->service->read('lesson', $this->scope, [], $adjacent['id'])['status']);
        self::assertSame('cancelled', $this->service->write('cancel_lesson', $this->scope, [], $adjacent['id'])['status']);
        $moved = $this->service->write('update_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Edited',
            'startsAt' => '2026-03-08T13:30:00Z', 'endsAt' => '2026-03-08T14:30:00Z', 'location' => 'Room B'], $lesson['id']);
        self::assertSame('2026-03-08T13:30:00.000000+00:00', $moved['startsAt']);
        $completed = $this->service->write('complete_lesson', $this->scope, [], $lesson['id']);
        self::assertSame('completed', $completed['status']);
        self::assertNotNull($completed['completedAt']);
        self::assertSame('completed', $this->service->write('complete_lesson', $this->scope, [], $lesson['id'])['status']);
        $this->assertError(409, fn() => $this->service->write('cancel_lesson', $this->scope, [], $lesson['id']), 'invalid_status_transition');
        $locked = ['groupId' => $group['id'], 'title' => 'Edited', 'startsAt' => '2026-03-08T13:30:00Z',
            'endsAt' => '2026-03-08T14:30:00Z', 'location' => 'Room B'];
        self::assertSame('completed', $this->service->write('update_lesson', $this->scope, $locked, $lesson['id'])['status']);
        $this->assertError(409, fn() => $this->service->write('update_lesson', $this->scope, [...$locked, 'title' => 'Changed'], $lesson['id']), 'lesson_locked');
        $overCompleted = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Historical overlap',
            'startsAt' => '2026-03-08T13:30:00Z', 'endsAt' => '2026-03-08T14:30:00Z']);
        $this->service->write('mark_attendance', $this->scope, ['status' => 'absent'], $lesson['id'], $student['id']);
        self::assertSame('absent', $this->service->read('participants', $this->scope, [], $lesson['id'])['items'][0]['status']);

        $active = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Group change',
            'startsAt' => '2026-03-08T12:00:00Z', 'endsAt' => '2026-03-08T13:00:00Z']);
        $this->service->write('mark_attendance', $this->scope, ['status' => 'present'], $active['id'], $student['id']);
        $this->assertError(409, fn() => $this->service->write('update_lesson', $this->scope, ['groupId' => $otherGroup['id'],
            'title' => 'Group change', 'startsAt' => '2026-03-08T12:00:00Z', 'endsAt' => '2026-03-08T13:00:00Z',
            'location' => ''], $active['id']), 'attendance_recorded');
        $this->service->write('mark_attendance', $this->scope, ['status' => 'pending'], $active['id'], $student['id']);
        $changed = $this->service->write('update_lesson', $this->scope, ['groupId' => $otherGroup['id'],
            'title' => 'Group change', 'startsAt' => '2026-03-08T12:00:00Z', 'endsAt' => '2026-03-08T13:00:00Z',
            'location' => ''], $active['id']);
        self::assertSame($otherGroup['id'], $changed['groupId']);
        self::assertSame([$otherStudent['id']], array_column($this->service->read('participants', $this->scope, [], $active['id'])['items'], 'id'));
        $this->service->write('cancel_lesson', $this->scope, [], $active['id']);
        $this->assertError(409, fn() => $this->service->write('mark_attendance', $this->scope, ['status' => 'present'], $active['id'], $otherStudent['id']), 'lesson_cancelled');
        $this->assertError(409, fn() => $this->service->write('complete_lesson', $this->scope, [], $active['id']), 'invalid_status_transition');
        self::assertSame('cancelled', $this->service->write('update_lesson', $this->scope, ['groupId' => $otherGroup['id'],
            'title' => 'Group change', 'startsAt' => '2026-03-08T12:00:00Z', 'endsAt' => '2026-03-08T13:00:00Z',
            'location' => ''], $active['id'])['status']);
        $this->assertError(409, fn() => $this->service->write('update_lesson', $this->scope, ['groupId' => $otherGroup['id'],
            'title' => 'Changed after cancel', 'startsAt' => '2026-03-08T12:00:00Z', 'endsAt' => '2026-03-08T13:00:00Z',
            'location' => ''], $active['id']), 'lesson_locked');

        $future = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Future',
            'startsAt' => '2030-01-01T10:00:00Z', 'endsAt' => '2030-01-01T11:00:00Z']);
        $this->assertError(409, fn() => $this->service->write('complete_lesson', $this->scope, [], $future['id']), 'lesson_not_started');
        self::assertSame('cancelled', $this->service->write('cancel_lesson', $this->scope, [], $future['id'])['status']);
        self::assertSame('cancelled', $this->service->write('cancel_lesson', $this->scope, [], $future['id'])['status']);
        $schedule = $this->service->read('schedule', $this->scope, ['from' => '2026-03-08', 'to' => '2026-03-08']);
        $byId = array_column($schedule['lessons'], null, 'id');
        self::assertSame('completed', $byId[$lesson['id']]['status']);
        self::assertSame('cancelled', $byId[$adjacent['id']]['status']);
        self::assertSame('scheduled', $byId[$overCompleted['id']]['status']);
    }

    public function testMaterialDeletionIsScopedAndCompletedHistoryIsRetained(): void
    {
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Materials group']);
        $person = $this->person('Material student');
        $this->member($person);
        $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $person]);
        $lesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Materials lesson',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z']);
        $bytes = "%PDF-1.4\nEducation material lifecycle\n%%EOF\n";
        $file = $this->service->upload($this->scope, new UploadedFile(
            (new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'lifecycle.pdf', 'text/plain',
        ), $lesson['id'], false);
        $lessonFile = $this->store->file($this->scope, $file['id']);
        $this->storedFiles[] = $lessonFile['storageKey'];
        $this->service->write('complete_lesson', $this->scope, [], $lesson['id']);
        self::assertSame($file['id'], $this->service->read('materials', $this->scope, [], $lesson['id'])['items'][0]['id']);
        self::assertSame('completed', $this->service->read('materials', $this->scope, [], $lesson['id'])['items'][0]['lessonStatus']);
        self::assertSame($bytes, file_get_contents($this->service->download($this->scope, $file['id'])['path']));
        $this->assertError(409, fn() => $this->service->write('delete_file', $this->scope, [], $file['id']), 'lesson_locked');
        self::assertNotNull($this->store->file($this->scope, $file['id']));

        $scheduled = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Cancelled materials',
            'startsAt' => '2026-03-08T15:00:00Z', 'endsAt' => '2026-03-08T16:00:00Z']);
        $cancelledFile = $this->service->upload($this->scope, new UploadedFile(
            (new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'cancelled.pdf', 'application/pdf',
        ), $scheduled['id'], false);
        $cancelledRow = $this->store->file($this->scope, $cancelledFile['id']);
        $this->storedFiles[] = $cancelledRow['storageKey'];
        $this->service->write('cancel_lesson', $this->scope, [], $scheduled['id']);
        self::assertSame(true, $this->service->write('delete_file', $this->scope, [], $cancelledFile['id'])['deleted']);
        self::assertNull($this->store->file($this->scope, $cancelledFile['id']));
        $this->assertError(404, fn() => $this->files->path($cancelledRow['storageKey']));

        $unattached = $this->service->upload($this->scope, new UploadedFile(
            (new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'unattached.pdf', 'application/pdf',
        ), null, false);
        $unattachedRow = $this->store->file($this->scope, $unattached['id']);
        $this->storedFiles[] = $unattachedRow['storageKey'];
        self::assertSame(true, $this->service->write('delete_file', $this->scope, [], $unattached['id'])['deleted']);
        self::assertNull($this->store->file($this->scope, $unattached['id']));

        $other = $this->teacher('Materials other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.files.manage');
        $this->assertError(404, fn() => $this->service->write('delete_file', $otherScope, [], $file['id']));
        self::assertNotNull($this->store->file($this->scope, $file['id']));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $avatar = $this->service->upload($this->scope, new UploadedFile(
            (new StreamFactory())->createStream($png), strlen($png), UPLOAD_ERR_OK, 'avatar.png', 'image/png',
        ), null, true);
        $avatarRow = $this->store->file($this->scope, $avatar['id']);
        $this->storedFiles[] = $avatarRow['storageKey'];
        $this->assertError(404, fn() => $this->service->write('delete_file', $this->scope, [], $avatar['id']));
    }

    public function testOtherTeachersAndRevokedPermissionsCannotUseNewOperations(): void
    {
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Protected group']);
        $person = $this->person('Protected student');
        $this->member($person);
        $student = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $person]);
        $lesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Protected lesson',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z']);
        $other = $this->teacher('Protected other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.people.manage');
        $this->assertError(404, fn() => $this->service->read('group_students', $otherScope, [], $group['id']));
        $this->assertError(404, fn() => $this->service->write('update_group', $otherScope, ['name' => 'Hijacked'], $group['id']));
        $this->assertError(404, fn() => $this->service->write('archive_group', $otherScope, [], $group['id']));
        $this->assertError(404, fn() => $this->service->write('remove_group_student', $otherScope, [], $group['id'], $student['id']));
        $this->assertError(404, fn() => $this->service->write('transfer_student', $otherScope, ['groupId' => $this->id()], $group['id'], $student['id']));
        $scheduleScope = $this->access->scope($other, $this->organization, 'education.schedule.manage');
        $this->assertError(404, fn() => $this->service->write('update_lesson', $scheduleScope, ['groupId' => $group['id'],
            'title' => 'Hijacked', 'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z', 'location' => ''], $lesson['id']));
        $this->assertError(404, fn() => $this->service->write('complete_lesson', $scheduleScope, [], $lesson['id']));
        $this->assertError(404, fn() => $this->service->write('cancel_lesson', $scheduleScope, [], $lesson['id']));

        $this->db->createCommand(
            "DELETE FROM core.role_permissions rp USING core.roles r, core.permissions p
            WHERE rp.role_id=r.id AND rp.permission_id=p.id AND r.code='education.teacher'
            AND p.code='education.people.manage'",
        )->execute();
        $this->assertError(403, fn() => $this->access->scope($this->actor, $this->organization, 'education.people.manage'));
        self::assertSame($this->organization, $this->access->scope($this->actor, $this->organization, 'education.schedule.manage')->organizationId);
    }

    public function testLocalDateRangesRespectBothDstTransitionsAndRejectInvalidInput(): void
    {
        [$start, $end] = Input::range('2026-03-08', '2026-03-08', 'America/New_York');
        self::assertSame(23 * 3600, strtotime($end) - strtotime($start));
        [$start, $end] = Input::range('2026-11-01', '2026-11-01', 'America/New_York');
        self::assertSame(25 * 3600, strtotime($end) - strtotime($start));
        $this->assertError(422, fn() => Input::date('2026-02-30'));
        $this->assertError(422, fn() => Input::range('2026-01-01', '2026-02-01', 'UTC'));
        $this->assertError(422, fn() => Input::instant('2026-03-08T09:00:00'));
        $this->assertError(422, fn() => $this->service->write('create_group', $this->scope, ['name' => 'Test', 'ownerAccountId' => $this->id()]));
        $registry = new DataCategoryRegistry(additionalDefinitions: [new DataCategoryDefinition('education')]);
        self::assertNotNull($registry->get('identity'));
        self::assertNotNull($registry->get('education'));
    }

    public function testMessagesAreRealAndOnlyThreadMembersCanReadThem(): void
    {
        $recipient = $this->teacher('Recipient');
        $outside = $this->teacher('Outside');
        $recipientScope = $this->access->scope($recipient, $this->organization, 'education.messages.use');
        $outsideScope = $this->access->scope($outside, $this->organization, 'education.messages.use');
        $thread = $this->service->write(
            'start_thread',
            $this->scope,
            ['recipientPersonId' => $recipient->personId, 'subject' => 'Class time', 'body' => 'Can we meet at ten?'],
        );
        self::assertSame(1, $this->service->read('header', $recipientScope, [])['unreadCount']);
        self::assertSame('Can we meet at ten?', $this->service->read('messages', $recipientScope, [], $thread['id'])['items'][0]['body']);
        $this->assertError(404, fn() => $this->service->read('messages', $outsideScope, [], $thread['id']));
        $this->assertError(404, fn() => $this->service->write('send_message', $outsideScope, ['body' => 'No'], $thread['id']));
        $firstId = $this->service->read('messages', $recipientScope, [], $thread['id'])['items'][0]['id'];
        $this->service->write('send_message', $this->scope, ['body' => 'Another message arrived.'], $thread['id']);
        $this->service->write('read_thread', $recipientScope, ['lastSeenMessageId' => $firstId], $thread['id']);
        self::assertSame(1, $this->service->read('header', $recipientScope, [])['unreadCount']);
        $lastId = $this->service->read('messages', $recipientScope, [], $thread['id'])['items'][1]['id'];
        $this->service->write('read_thread', $recipientScope, ['lastSeenMessageId' => $lastId], $thread['id']);
        self::assertSame(0, $this->service->read('header', $recipientScope, [])['unreadCount']);
        $this->service->write('send_message', $recipientScope, ['body' => 'Yes.'], $thread['id']);
        self::assertCount(3, $this->service->read('messages', $this->scope, [], $thread['id'])['items']);
        self::assertSame(1, $this->service->read('header', $this->scope, [])['unreadCount']);
    }

    public function testActualFileBytesMimeScopeAndAvatarProfile(): void
    {
        $bytes = "%PDF-1.4\nEducation integration test\n%%EOF\n";
        $upload = new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'lesson.pdf', 'text/html');
        $file = $this->service->upload($this->scope, $upload, null, false);
        $row = $this->store->file($this->scope, $file['id']);
        $this->storedFiles[] = $row['storageKey'];
        self::assertSame('application/pdf', $file['mime']);
        $download = $this->service->download($this->scope, $file['id']);
        self::assertSame($bytes, file_get_contents($download['path']));
        self::assertSame(hash('sha256', $bytes), $download['sha256']);
        $other = $this->teacher('Other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.files.manage');
        $this->assertError(404, fn() => $this->service->download($otherScope, $file['id']));
        $this->assertError(422, fn() => $this->service->write('save_profile', $this->scope, ['displayName' => 'Teacher', 'avatarFileId' => $file['id']]));
        $html = '<html><script>alert(1)</script></html>';
        $this->assertError(422, fn() => $this->service->upload(
            $this->scope,
            new UploadedFile((new StreamFactory())->createStream($html), strlen($html), UPLOAD_ERR_OK, 'safe.pdf', 'application/pdf'),
            null,
            false,
        ));
        $this->service->write('save_profile', $this->scope, ['displayName' => 'My real profile alias', 'avatarFileId' => null]);
        self::assertSame('My real profile alias', $this->service->read('header', $this->scope, [])['displayName']);
    }

    public function testSubjectExportCorrectionAndResourceProcessingBlock(): void
    {
        $this->service->write('save_profile', $this->scope, ['displayName' => 'Own alias', 'avatarFileId' => null]);
        $other = $this->teacher('Other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.access');
        $this->service->write('save_profile', $otherScope, ['displayName' => 'Other private alias', 'avatarFileId' => null]);
        $profile = $this->store->profile($this->scope);
        $export = new \App\Education\Infrastructure\PersonalDataContributor($this->db);
        $section = $export->collect(new \App\Core\Privacy\Application\PersonalDataExportContext(
            $this->scope->personId,
            $this->id(),
            $this->scope->personId,
            $this->scope->accountId,
            null,
            new DateTimeImmutable(),
        ));
        $profiles = array_values(array_filter($section->records, static fn(array $row): bool => $row['type'] === 'profile'));
        self::assertCount(1, $profiles);
        self::assertSame('Own alias', $profiles[0]['displayName']);
        $correction = new \App\Education\Infrastructure\ProfileCorrection($this->db);
        $command = new \App\Core\Privacy\Application\CorrectionCommand('education.profile', $profile['id'], ['displayName' => 'Corrected']);
        $correction->apply(new \App\Core\Privacy\Application\CorrectionContext($this->id(), $this->scope->personId), $command);
        self::assertSame('Corrected', $this->service->read('header', $this->scope, [])['displayName']);
        try {
            $correction->apply(new \App\Core\Privacy\Application\CorrectionContext($this->id(), $otherScope->personId), $command);
            self::fail('Another subject corrected a private profile.');
        } catch (\DomainException) {
            self::assertSame('Corrected', $this->store->profile($this->scope)['displayName']);
        }
        $this->db->createCommand(
            "INSERT INTO core.privacy_processing_blocks(subject_person_id,scope_type,resource_type,resource_id,reason_code,created_by_account_id,started_at)
            VALUES(CAST(:person AS uuid),'resource','education.profile',CAST(:resource AS uuid),'subject_request',CAST(:actor AS uuid),now()-interval '1 minute')",
            [':person' => $this->scope->personId, ':resource' => $profile['id'], ':actor' => $this->scope->accountId],
        )->execute();
        $this->assertError(403, fn() => $this->service->read('header', $this->scope, []));
    }

    public function testPrivacyContributorsCoverEducationWorkspace(): void
    {
        $this->service->write('save_profile', $this->scope, ['displayName' => 'Retention subject', 'avatarFileId' => null]);
        $categories = new DataCategoryRegistry(additionalDefinitions: [new DataCategoryDefinition('education')]);
        $policies = new RetentionPolicyRegistry([
            new RetentionPolicyDefinition('education.workspace', 'withdrawal', null, 'delete'),
        ]);
        $purposes = new ProcessingPurposeRegistry([
            new ProcessingPurposeDefinition(
                'education.consent',
                'consent',
                true,
                false,
                ['education'],
                ['person'],
                'education.workspace',
                'operator',
            ),
        ], $categories, $policies);
        $contributor = new RetentionCandidateContributor($this->db, $purposes, $policies);
        self::assertSame('education.workspace', $contributor->resourceType());
        self::assertTrue($contributor->supportsPurpose('education.consent'));
        $candidates = iterator_to_array($contributor->candidates(10, $this->scope->personId));
        self::assertCount(1, $candidates);
        self::assertSame('education.workspace', $candidates[0]->resourceType);
        self::assertSame($this->scope->personId, $candidates[0]->resourceId);
        self::assertSame('education.consent', $candidates[0]->purposeCode);
        self::assertSame('education.workspace', $candidates[0]->retentionPolicyCode);
        self::assertNull($candidates[0]->triggeredAt);

        $contracts = new ProcessingPurposeRegistry([
            new ProcessingPurposeDefinition(
                'education.contract',
                'contract',
                false,
                false,
                ['education'],
                ['person'],
                'education.workspace',
                'operator',
            ),
        ], $categories, $policies);
        $basis = new LawfulProcessingBasisContributor($this->db, $contracts);
        $candidate = new RetentionCandidate(
            $this->scope->personId,
            'education.workspace',
            $this->scope->personId,
            'education.contract',
            'education.workspace',
            'withdrawal',
            null,
            'education.workspace_retention',
        );
        self::assertTrue($basis->supports('education.contract', 'education.workspace'));
        self::assertSame('active', $basis->resolve($candidate));
        $this->db->createCommand(
            "UPDATE core.organization_memberships SET status='left', left_at=now() WHERE person_id=CAST(:person AS uuid)",
            [':person' => $this->scope->personId],
        )->execute();
        $this->db->createCommand(
            "UPDATE core.accounts SET status='archived', archived_at=now() WHERE person_id=CAST(:person AS uuid)",
            [':person' => $this->scope->personId],
        )->execute();
        self::assertSame('inactive', $basis->resolve($candidate));

        $restriction = new ProcessingRestrictionContributor($purposes);
        self::assertTrue($restriction->supports('purpose', 'education.consent', null));
        self::assertFalse($restriction->supports('purpose', 'test.consent', null));
        $block = (string) $this->db->createCommand('SELECT uuidv7()')->queryScalar();
        $restriction->applyRestriction($block, $this->scope->personId);
        $restriction->releaseRestriction($block, $this->scope->personId);
    }

    public function testWorkspaceErasureRemovesDatabaseFilesAndIsIdempotent(): void
    {
        $this->service->write('save_profile', $this->scope, ['displayName' => 'Erase subject', 'avatarFileId' => null]);
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Erase group']);
        $studentPerson = $this->person('Erase student');
        $this->member($studentPerson);
        $student = $this->service->write('enroll_student', $this->scope, [
            'groupId' => $group['id'],
            'personId' => $studentPerson,
        ]);
        $lesson = $this->service->write('create_lesson', $this->scope, [
            'groupId' => $group['id'],
            'title' => 'Erase lesson',
            'startsAt' => '2026-03-08T13:30:00Z',
            'endsAt' => '2026-03-08T15:00:00Z',
        ]);
        $this->service->write('mark_attendance', $this->scope, ['status' => 'present'], $lesson['id'], $student['id']);
        $this->service->write('complete_lesson', $this->scope, [], $lesson['id']);
        $this->service->write('save_lesson_report', $this->scope, ['topic' => 'Erase report', 'summary' => 'Erase summary',
            'teacherComment' => null], $lesson['id']);
        $this->service->write('save_lesson_result', $this->scope, ['status' => 'mastered', 'comment' => null], $lesson['id'], $student['id']);
        $this->service->write('create_event', $this->scope, [
            'title' => 'Erase event',
            'kind' => 'other',
            'startsAt' => '2026-03-08T16:00:00Z',
            'endsAt' => '2026-03-08T17:00:00Z',
        ]);
        $bytes = "%PDF-1.4\nEducation erasure test\n%%EOF\n";
        $file = $this->service->upload(
            $this->scope,
            new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'erase.pdf', 'application/pdf'),
            null,
            false,
        );
        $fileRow = $this->store->file($this->scope, $file['id']);
        $this->storedFiles[] = $fileRow['storageKey'];
        $recipient = $this->teacher('Erase recipient');
        $thread = $this->service->write('start_thread', $this->scope, [
            'recipientPersonId' => $recipient->personId,
            'subject' => 'Erase thread',
            'body' => 'Erase message',
        ]);
        $other = $this->teacher('Keep other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.access');
        $otherGroup = $this->service->write('create_group', $otherScope, ['name' => 'Keep group']);

        $handler = new WorkspaceErasureHandler($this->db, $this->files);
        self::assertTrue($handler->supportsAction('delete'));
        self::assertFalse($handler->supportsAction('anonymize'));
        self::assertTrue($handler->owns($this->scope->personId, $this->scope->personId));
        self::assertFalse($handler->owns($this->scope->personId, $recipient->personId));
        $item = (string) $this->db->createCommand('SELECT uuidv7()')->queryScalar();
        self::assertSame('deleted', $handler->execute(
            $this->scope->personId,
            $this->scope->personId,
            'delete',
            $item,
        ));
        self::assertSame('deleted', $handler->reconcile($item));

        $count = fn(string $sql, array $params = []): int => (int) $this->db->createCommand($sql, $params)->queryScalar();
        $person = [':person' => $this->scope->personId];
        self::assertSame(0, $count('SELECT count(*) FROM education.profiles WHERE person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count('SELECT count(*) FROM education.teachers WHERE person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count('SELECT count(*) FROM education.groups WHERE owner_person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count('SELECT count(*) FROM education.lessons WHERE owner_person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count('SELECT count(*) FROM education.events WHERE owner_person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count('SELECT count(*) FROM education.files WHERE owner_person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count('SELECT count(*) FROM education.messages WHERE sender_person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count('SELECT count(*) FROM education.notifications WHERE person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count('SELECT count(*) FROM education.thread_members WHERE person_id = CAST(:person AS uuid)', $person));
        self::assertSame(0, $count(
            'SELECT count(*) FROM education.students WHERE person_id = CAST(:person AS uuid)'
            . ' OR owner_account_id IN (SELECT id FROM core.accounts WHERE person_id = CAST(:person AS uuid))',
            $person,
        ));
        self::assertSame(0, $count(
            'SELECT count(*) FROM education.attendance WHERE lesson_id = CAST(:lesson AS uuid) OR student_id = CAST(:student AS uuid)',
            [':lesson' => $lesson['id'], ':student' => $student['id']],
        ));
        self::assertSame(0, $count(
            'SELECT count(*) FROM education.group_students WHERE group_id = CAST(:group AS uuid) OR student_id = CAST(:student AS uuid)',
            [':group' => $group['id'], ':student' => $student['id']],
        ));
        self::assertSame(0, $count('SELECT count(*) FROM education.lesson_results WHERE lesson_id = CAST(:lesson AS uuid) OR student_id = CAST(:student AS uuid)',
            [':lesson' => $lesson['id'], ':student' => $student['id']]));
        self::assertSame(0, $count('SELECT count(*) FROM education.lesson_reports WHERE lesson_id = CAST(:lesson AS uuid)', [':lesson' => $lesson['id']]));
        self::assertSame(0, $count('SELECT count(*) FROM education.result_revisions'));
        self::assertSame(1, $count('SELECT count(*) FROM education.privacy_receipts'));
        self::assertSame(1, $count('SELECT count(*) FROM education.groups WHERE id = CAST(:group AS uuid)', [':group' => $otherGroup['id']]));
        self::assertSame(1, $count('SELECT count(*) FROM education.teachers WHERE person_id = CAST(:person AS uuid)', [':person' => $recipient->personId]));
        self::assertSame(0, $count('SELECT count(*) FROM education.notifications WHERE person_id = CAST(:person AS uuid)', [':person' => $recipient->personId]));
        self::assertSame(1, $count(
            'SELECT count(*) FROM education.thread_members WHERE thread_id = CAST(:thread AS uuid) AND person_id = CAST(:person AS uuid)',
            [':thread' => $thread['id'], ':person' => $recipient->personId],
        ));
        self::assertSame(1, $count('SELECT count(*) FROM education.threads WHERE id = CAST(:thread AS uuid)', [':thread' => $thread['id']]));
        try {
            $this->files->path($fileRow['storageKey']);
            self::fail('Erased Education file bytes still exist.');
        } catch (EducationError) {
            self::assertTrue(true);
        }

        $second = (string) $this->db->createCommand('SELECT uuidv7()')->queryScalar();
        self::assertSame('deleted', $handler->execute(
            $this->scope->personId,
            $this->scope->personId,
            'delete',
            $second,
        ));
        self::assertSame(2, $count('SELECT count(*) FROM education.privacy_receipts'));
    }

    public function testWorkspaceErasureHandlerIsRegisteredInContainer(): void
    {
        $registry = $this->container->get(PersonalDataErasureHandlerRegistry::class);
        self::assertInstanceOf(WorkspaceErasureHandler::class, $registry->forAction('education.workspace', 'delete'));
        self::assertNull($registry->forAction('education.workspace', 'anonymize'));
    }

    public function testLessonReportsAndResultsRequireCompletedLessonAndKeepCorrectionHistory(): void
    {
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Results group']);
        $person = $this->person('Results student');
        $this->member($person);
        $student = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $person]);
        $lesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Results lesson',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z']);
        $this->assertError(409, fn() => $this->service->write('save_lesson_report', $this->scope, [
            'topic' => 'Vectors', 'summary' => 'Scalar product', 'teacherComment' => 'Next: basis',
        ], $lesson['id']), 'lesson_not_completed');
        $this->assertError(409, fn() => $this->service->write('save_lesson_result', $this->scope, [
            'status' => 'mastered', 'comment' => null,
        ], $lesson['id'], $student['id']), 'lesson_not_completed');
        $this->service->write('complete_lesson', $this->scope, [], $lesson['id']);

        $report = $this->service->write('save_lesson_report', $this->scope, [
            'topic' => 'Vectors', 'summary' => 'Scalar product', 'teacherComment' => 'Next: basis',
        ], $lesson['id']);
        self::assertSame('Vectors', $report['topic']);
        self::assertSame('Scalar product', $report['summary']);
        self::assertSame('Next: basis', $report['teacherComment']);
        $reapplied = $this->service->write('save_lesson_report', $this->scope, [
            'topic' => 'Vectors', 'summary' => 'Scalar product', 'teacherComment' => 'Next: basis',
        ], $lesson['id']);
        self::assertSame($report['id'], $reapplied['id']);
        $this->service->write('save_lesson_report', $this->scope, [
            'topic' => 'Vector algebra', 'summary' => 'Scalar product and basis', 'teacherComment' => null,
        ], $lesson['id']);
        $results = $this->service->read('lesson_results', $this->scope, [], $lesson['id']);
        self::assertNull($results['report']['teacherComment']);
        self::assertSame('Vector algebra', $results['report']['topic']);
        self::assertSame('pending', $results['items'][0]['status']);
        self::assertNull($results['items'][0]['comment']);

        $saved = $this->service->write('save_lesson_result', $this->scope, [
            'status' => 'mastered', 'comment' => 'Confident',
        ], $lesson['id'], $student['id']);
        self::assertSame('mastered', $saved['status']);
        self::assertSame('Confident', $saved['comment']);
        $corrected = $this->service->write('save_lesson_result', $this->scope, [
            'status' => 'developing', 'comment' => 'Needs more practice',
        ], $lesson['id'], $student['id']);
        self::assertSame($saved['id'], $corrected['id']);
        $revisions = $this->store->lessonResultRevisions($this->scope, $lesson['id'], $student['id']);
        self::assertCount(2, $revisions);
        self::assertSame('mastered', $revisions[0]['status']);
        self::assertSame('developing', $revisions[1]['status']);
        $results = $this->service->read('lesson_results', $this->scope, [], $lesson['id']);
        self::assertSame('developing', $results['items'][0]['status']);
        self::assertSame('Needs more practice', $results['items'][0]['comment']);

        $this->service->write('remove_group_student', $this->scope, [], $group['id'], $student['id']);
        $results = $this->service->read('lesson_results', $this->scope, [], $lesson['id']);
        self::assertCount(1, $results['items']);
        self::assertSame('developing', $results['items'][0]['status']);

        $cancelled = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Cancelled results',
            'startsAt' => '2026-03-08T15:00:00Z', 'endsAt' => '2026-03-08T16:00:00Z']);
        $this->service->write('cancel_lesson', $this->scope, [], $cancelled['id']);
        $this->assertError(409, fn() => $this->service->write('save_lesson_report', $this->scope, [
            'topic' => 'Blocked', 'summary' => 'Blocked', 'teacherComment' => null,
        ], $cancelled['id']), 'lesson_cancelled');
        $this->assertError(409, fn() => $this->service->write('save_lesson_result', $this->scope, [
            'status' => 'mastered', 'comment' => null,
        ], $cancelled['id'], $student['id']), 'lesson_cancelled');

        $other = $this->teacher('Results other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.schedule.manage');
        $this->assertError(404, fn() => $this->service->read('lesson_results', $otherScope, [], $lesson['id']));
        $this->assertError(404, fn() => $this->service->write('save_lesson_report', $otherScope, [
            'topic' => 'Hijacked', 'summary' => 'Hijacked', 'teacherComment' => null,
        ], $lesson['id']));
        $this->assertError(404, fn() => $this->service->write('save_lesson_result', $otherScope, [
            'status' => 'mastered', 'comment' => null,
        ], $lesson['id'], $student['id']));
        $this->assertError(422, fn() => $this->service->write('save_lesson_result', $this->scope, [
            'status' => 'five', 'comment' => null,
        ], $lesson['id'], $student['id']));
        $this->assertError(404, fn() => $this->service->write('save_lesson_result', $this->scope, [
            'status' => 'mastered', 'comment' => null,
        ], $lesson['id'], $this->id()));
    }

    public function testGroupJournalListsCompletedLessonsWithFiltersAndPagination(): void
    {
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Journal group']);
        $firstPerson = $this->person('Journal first');
        $secondPerson = $this->person('Journal second');
        $this->member($firstPerson);
        $this->member($secondPerson);
        $first = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $firstPerson]);
        $second = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $secondPerson]);
        $firstLesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'First conducted',
            'startsAt' => '2026-03-07T13:00:00Z', 'endsAt' => '2026-03-07T14:00:00Z']);
        $secondLesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Second conducted',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z']);
        $scheduled = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Still scheduled',
            'startsAt' => '2026-03-08T16:00:00Z', 'endsAt' => '2026-03-08T17:00:00Z']);
        $this->service->write('complete_lesson', $this->scope, [], $firstLesson['id']);
        $this->service->write('complete_lesson', $this->scope, [], $secondLesson['id']);
        $this->service->write('save_lesson_report', $this->scope, ['topic' => 'Topic one', 'summary' => 'Summary one',
            'teacherComment' => null], $firstLesson['id']);
        $this->service->write('save_lesson_result', $this->scope, ['status' => 'mastered', 'comment' => null], $firstLesson['id'], $first['id']);
        $this->service->write('save_lesson_result', $this->scope, ['status' => 'developing', 'comment' => 'Try again'], $secondLesson['id'], $first['id']);
        $this->service->write('save_lesson_result', $this->scope, ['status' => 'mastered', 'comment' => null], $secondLesson['id'], $second['id']);

        $journal = $this->service->read('group_journal', $this->scope, [], $group['id']);
        self::assertSame([$secondLesson['id'], $firstLesson['id']], array_column($journal['items'], 'id'));
        self::assertSame('Topic one', $journal['items'][1]['report']['topic']);
        self::assertNull($journal['items'][0]['report']);
        self::assertSame('Try again', $journal['items'][0]['results'][0]['comment']);
        self::assertCount(2, $journal['items'][0]['results']);
        self::assertSame('pending', $journal['items'][1]['results'][1]['status']);

        $filtered = $this->service->read('group_journal', $this->scope, ['studentId' => $second['id']], $group['id']);
        self::assertSame([$secondLesson['id'], $firstLesson['id']], array_column($filtered['items'], 'id'));
        self::assertSame([$second['id']], array_column($filtered['items'][0]['results'], 'studentId'));
        self::assertSame('mastered', $filtered['items'][0]['results'][0]['status']);
        self::assertSame('pending', $filtered['items'][1]['results'][0]['status']);

        $period = $this->service->read('group_journal', $this->scope, ['from' => '2026-03-07', 'to' => '2026-03-07'], $group['id']);
        self::assertSame([$firstLesson['id']], array_column($period['items'], 'id'));
        $this->assertError(422, fn() => $this->service->read('group_journal', $this->scope, ['from' => '2026-03-07'], $group['id']), 'invalid_range');
        $this->assertError(404, fn() => $this->service->read('group_journal', $this->scope, ['studentId' => $this->id()], $group['id']));

        $other = $this->teacher('Journal other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.people.manage');
        $this->assertError(404, fn() => $this->service->read('group_journal', $otherScope, [], $group['id']));
        self::assertNotContains($scheduled['id'], array_column($journal['items'], 'id'));
    }

    public function testStudentCardKeepsHistoryAcrossRemovalTransferAndArchive(): void
    {
        $group = $this->service->write('create_group', $this->scope, ['name' => 'Card group']);
        $target = $this->service->write('create_group', $this->scope, ['name' => 'Card target']);
        $person = $this->person('Card student');
        $this->member($person);
        $student = $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $person]);
        $firstLesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Card first',
            'startsAt' => '2026-03-07T13:00:00Z', 'endsAt' => '2026-03-07T14:00:00Z']);
        $secondLesson = $this->service->write('create_lesson', $this->scope, ['groupId' => $group['id'], 'title' => 'Card second',
            'startsAt' => '2026-03-08T13:00:00Z', 'endsAt' => '2026-03-08T14:00:00Z']);
        $this->service->write('complete_lesson', $this->scope, [], $firstLesson['id']);
        $this->service->write('complete_lesson', $this->scope, [], $secondLesson['id']);
        $this->service->write('mark_attendance', $this->scope, ['status' => 'present'], $firstLesson['id'], $student['id']);
        $this->service->write('mark_attendance', $this->scope, ['status' => 'absent'], $secondLesson['id'], $student['id']);
        $this->service->write('save_lesson_result', $this->scope, ['status' => 'mastered', 'comment' => 'Great work'], $firstLesson['id'], $student['id']);
        $this->service->write('save_lesson_result', $this->scope, ['status' => 'needs_support', 'comment' => 'Review basics'], $secondLesson['id'], $student['id']);

        $card = $this->service->read('student', $this->scope, [], $student['id']);
        self::assertSame('Card student Test', $card['name']);
        self::assertSame([$group['id']], array_column($card['groups'], 'id'));

        $attendance = $this->service->read('student_attendance', $this->scope, ['from' => '2026-03-07', 'to' => '2026-03-08'], $student['id']);
        self::assertSame('absent', $attendance['items'][0]['attendance']);
        self::assertSame('present', $attendance['items'][1]['attendance']);

        $results = $this->service->read('student_results', $this->scope, [], $student['id']);
        self::assertSame([$secondLesson['id'], $firstLesson['id']], array_column($results['items'], 'lessonId'));
        self::assertSame('Great work', $results['items'][1]['comment']);

        $progress = $this->service->read('student_progress', $this->scope, ['from' => '2026-03-07', 'to' => '2026-03-08'], $student['id']);
        self::assertSame('2026-03-07', $progress['from']);
        self::assertSame('2026-03-08', $progress['to']);
        self::assertSame(['mastered' => 1, 'developing' => 0, 'needs_support' => 1], $progress['results']);
        self::assertSame(['present' => 1, 'absent' => 1], $progress['attendance']);

        $this->service->write('transfer_student', $this->scope, ['groupId' => $target['id']], $group['id'], $student['id']);
        self::assertCount(2, $this->service->read('student_results', $this->scope, [], $student['id'])['items']);
        self::assertSame([$target['id']], array_column($this->service->read('student', $this->scope, [], $student['id'])['groups'], 'id'));
        $this->service->write('remove_group_student', $this->scope, [], $target['id'], $student['id']);
        self::assertSame([], $this->service->read('student', $this->scope, [], $student['id'])['groups']);
        self::assertSame('mastered', $this->service->read('student_results', $this->scope, [], $student['id'])['items'][1]['status']);
        $this->service->write('enroll_student', $this->scope, ['groupId' => $group['id'], 'personId' => $person]);
        $this->service->write('archive_group', $this->scope, [], $group['id']);
        self::assertSame([$group['id']], array_column($this->service->read('student', $this->scope, [], $student['id'])['groups'], 'id'));
        self::assertCount(2, $this->service->read('student_results', $this->scope, [], $student['id'])['items']);

        $other = $this->teacher('Card other');
        $otherScope = $this->access->scope($other, $this->organization, 'education.people.manage');
        $this->assertError(404, fn() => $this->service->read('student', $otherScope, [], $student['id']));
        $this->assertError(404, fn() => $this->service->read('student_results', $otherScope, [], $student['id']));
    }

    private function teacher(string $name): AuthenticatedIdentity
    {
        $person = $this->person($name);
        $this->member($person);
        $account = (string) $this->db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label)
            VALUES(CAST(:person AS uuid),'human','active',:label) RETURNING id::text", [':person' => $person, ':label' => $name])->queryScalar();
        $this->db->createCommand("INSERT INTO core.account_roles(account_id,role_id)
            SELECT CAST(:account AS uuid),id FROM core.roles WHERE code='education.teacher'", [':account' => $account])->execute();
        $this->store->grantTeacher($this->organization, $account, $person);
        return new AuthenticatedIdentity($account, $person, $this->id(), ['education.teacher'], [], 'en-US', 'America/New_York');
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

    private function serviceAt(string $moment): EducationService
    {
        $clock = new class($moment) implements ClockInterface {
            public function __construct(private readonly string $moment) {}
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->moment);
            }
        };
        return new EducationService($this->store, $this->access, $clock, $this->files,
            $this->container->get(Mutation::class), $this->container->get(PermissionService::class),
            $this->container->get(OrganizationRepository::class));
    }
}
