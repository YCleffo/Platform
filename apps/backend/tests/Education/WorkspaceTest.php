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
        $this->service = new EducationService($this->store, $this->access, $clock, $this->files, $container->get(Mutation::class));
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

    private function assertError(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected Education request rejection.');
        } catch (EducationError $error) {
            self::assertSame($status, $error->status);
        }
    }
}
