<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Core\Time\Application\ClockInterface;
use App\DeviceManagement\Application\DeviceService;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationService;
use App\Education\Application\EducationStore;
use App\Education\Application\FileStore;
use App\Environment;
use App\Storage\Application\StorageAccess;
use App\Storage\Application\StorageError;
use App\Storage\Application\StorageScope;
use App\Storage\Application\StorageService;
use App\Storage\Application\StorageStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

final class StorageTest extends TestCase
{
    private ConnectionInterface $db;
    private ContainerInterface $container;
    private TransactionInterface $transaction;
    private StorageAccess $access;
    private StorageStore $store;
    private StorageService $service;
    private EducationService $education;
    private EducationAccess $educationAccess;
    private DeviceService $devices;
    private AuthenticatedIdentity $teacher;
    private AuthenticatedIdentity $studentIdentity;
    private StorageScope $studentScope;
    private string $studentId;
    private string $organization;

    protected function setUp(): void
    {
        self::assertSame('1', getenv('EDUCATION_ENABLED'), 'Run the dedicated Education suite with its module enabled.');
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $this->container = $container;
        $this->db = $container->get(ConnectionInterface::class);
        TestRuntimeGuard::assertDestructiveOperationsAllowed($this->db);
        $this->transaction = $this->db->beginTransaction();
        foreach (['education', 'storage', 'device_management'] as $module) {
            $this->db->createCommand("UPDATE core.modules SET enabled=true,enabled_at=now(),disabled_at=NULL WHERE code=:code",
                [':code' => $module])->execute();
        }
        $this->store = $container->get(StorageStore::class);
        $this->access = $container->get(StorageAccess::class);
        $this->service = $container->get(StorageService::class);
        $this->devices = $container->get(DeviceService::class);
        $this->educationAccess = $container->get(EducationAccess::class);
        $educationStore = $container->get(EducationStore::class);
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-03-08T14:00:00Z');
            }
        };
        $this->education = new EducationService($educationStore, $this->educationAccess, $clock,
            $container->get(FileStore::class), $container->get(\App\Education\Application\Mutation::class),
            $container->get(PermissionService::class), $container->get(OrganizationRepository::class));
        $this->organization = (string) $this->db->createCommand("INSERT INTO core.organizations(code,name)
            VALUES(:code,'Storage test') RETURNING id::text", [':code' => 'storage.test.' . bin2hex(random_bytes(8))])->queryScalar();
        $person = $this->person('Storage teacher');
        $this->member($person);
        $teacherAccount = $this->account($person, 'Storage teacher', 'education.teacher');
        $educationStore->grantTeacher($this->organization, $teacherAccount, $person);
        $this->teacher = new AuthenticatedIdentity($teacherAccount, $person, $this->id(), ['education.teacher'], [], 'en-US', 'UTC');
        $studentPerson = $this->person('Storage student');
        $this->member($studentPerson);
        $group = $this->education->write('create_group', $this->educationScope(), ['name' => 'Storage group']);
        $student = $this->education->write('enroll_student', $this->educationScope(), ['groupId' => $group['id'], 'personId' => $studentPerson]);
        $this->studentId = (string) $student['id'];
        $studentAccount = $this->account($studentPerson, 'Storage student', 'education.student');
        $invite = $this->education->write('invite_student', $this->educationScope(), [], $student['id']);
        $this->studentIdentity = new AuthenticatedIdentity($studentAccount, $studentPerson, $this->id(), ['education.student'], [], 'ru-RU', 'UTC');
        $this->education->activateInvite($this->studentIdentity, $studentPerson, ['code' => $invite['code']]);
        $this->studentScope = $this->access->studentScope($this->studentIdentity, $this->organization);
    }

    protected function tearDown(): void
    {
        $this->transaction->rollBack();
    }

    public function testStudentUploadsVersionsAndDownloadsWithTickets(): void
    {
        $space = $this->service->read('space', $this->studentScope, []);
        self::assertSame(0, $space['fileCount']);

        $uploadTicket = $this->service->write('ticket', $this->studentScope, ['purpose' => 'upload', 'path' => 'docs/hello.txt']);
        $content = 'Hello documents';
        $first = $this->service->uploadWithTicket($uploadTicket['token'], $this->stream($content), hash('sha256', $content));
        self::assertSame(1, $first['version']);
        $this->assertError(404, fn() => $this->service->uploadWithTicket($uploadTicket['token'], $this->stream($content), null));

        $files = $this->service->read('files', $this->studentScope, []);
        self::assertSame([['path' => 'docs/hello.txt', 'version' => 1]],
            array_map(static fn(array $row): array => ['path' => $row['path'], 'version' => $row['version']], $files['items']));

        $downloadTicket = $this->service->write('ticket', $this->studentScope, ['purpose' => 'download', 'path' => 'docs/hello.txt']);
        $download = $this->service->downloadWithTicket($downloadTicket['token']);
        self::assertSame($content, stream_get_contents($download['stream']));
        self::assertSame(hash('sha256', $content), $download['sha256']);
        $this->assertError(404, fn() => $this->service->downloadWithTicket($downloadTicket['token']));

        $secondContent = 'Hello documents v2';
        $secondTicket = $this->service->write('ticket', $this->studentScope, ['purpose' => 'upload', 'path' => 'docs/hello.txt']);
        $second = $this->service->uploadWithTicket($secondTicket['token'], $this->stream($secondContent), hash('sha256', $secondContent));
        self::assertSame(2, $second['version']);
        self::assertSame(1, $this->service->read('space', $this->studentScope, [])['fileCount']);
    }

    public function testPathTraversalAndLimitsAreRejected(): void
    {
        $this->assertError(422, fn() => $this->service->write('ticket', $this->studentScope,
            ['purpose' => 'upload', 'path' => '../secret.txt']));
        $this->assertError(422, fn() => $this->service->write('ticket', $this->studentScope,
            ['purpose' => 'upload', 'path' => '/absolute.txt']));
        $this->assertError(422, fn() => $this->service->write('ticket', $this->studentScope,
            ['purpose' => 'upload', 'path' => 'a/../b.txt']));
        $this->assertError(422, fn() => $this->service->write('ticket', $this->studentScope,
            ['purpose' => 'upload', 'path' => '']));
        $this->assertError(422, fn() => $this->service->write('ticket', $this->studentScope,
            ['purpose' => 'nonsense', 'path' => 'a.txt']));
        $ticket = $this->service->write('ticket', $this->studentScope, ['purpose' => 'upload', 'path' => 'nested\\folder\\a.txt']);
        $content = str_repeat('x', 1024);
        $this->service->uploadWithTicket($ticket['token'], $this->stream($content), hash('sha256', $content));
        self::assertSame('nested/folder/a.txt', $this->service->read('files', $this->studentScope, [])['items'][0]['path']);
    }

    public function testAgentManifestUploadAndConflictResolution(): void
    {
        $device = $this->devices->write('create_device', $this->deviceScope(), ['name' => 'Sync PC', 'number' => '5']);
        $enrollment = $this->devices->write('device_enroll', $this->deviceScope(), [], $device['id']);
        $agent = $this->devices->agentEnroll(['code' => $enrollment['code']]);
        $this->devices->write('device_session', $this->deviceScope(), ['studentId' => $this->studentId], $device['id']);

        $space = $this->service->read('space', $this->studentScope, []);
        $content = 'manifest content';
        $sha = hash('sha256', $content);
        $manifest = $this->service->agentManifest($device['id'], [
            'version' => 1,
            'entries' => [['path' => 'notes.txt', 'op' => 'add', 'sha256' => $sha, 'size' => strlen($content)]],
        ]);
        self::assertCount(1, $manifest['uploads']);
        $uploaded = $this->service->uploadWithTicket($manifest['uploads'][0]['token'], $this->stream($content), $sha);
        self::assertSame(1, $uploaded['version']);
        $confirmed = $this->service->agentSync($device['id'], ['version' => 1]);
        self::assertTrue($confirmed['confirmed']);

        $manifestTwo = $this->service->agentManifest($device['id'], [
            'version' => 2,
            'entries' => [['path' => 'notes.txt', 'op' => 'update', 'sha256' => str_repeat('a', 64), 'size' => 12]],
        ]);
        self::assertCount(1, $manifestTwo['conflicts']);
        self::assertSame($sha, $manifestTwo['conflicts'][0]['serverSha256']);
        self::assertSame(1, $this->service->read('space', $this->studentScope, [])['fileCount']);
        self::assertSame($agent['credential'], $agent['credential']);
        self::assertSame($space['id'], $this->service->agentSpace($device['id'])['id']);
    }

    public function testStudentCannotTouchAnotherSpace(): void
    {
        $secondPerson = $this->person('Other storage student');
        $this->member($secondPerson);
        $group = $this->education->write('create_group', $this->educationScope(), ['name' => 'Other group']);
        $secondStudent = $this->education->write('enroll_student', $this->educationScope(), ['groupId' => $group['id'], 'personId' => $secondPerson]);
        $secondAccount = $this->account($secondPerson, 'Other storage student', 'education.student');
        $invite = $this->education->write('invite_student', $this->educationScope(), [], $secondStudent['id']);
        $secondIdentity = new AuthenticatedIdentity($secondAccount, $secondPerson, $this->id(), ['education.student'], [], 'ru-RU', 'UTC');
        $this->education->activateInvite($secondIdentity, $secondPerson, ['code' => $invite['code']]);
        $secondScope = $this->access->studentScope($secondIdentity, $this->organization);

        $ticket = $this->service->write('ticket', $this->studentScope, ['purpose' => 'upload', 'path' => 'mine.txt']);
        $this->service->uploadWithTicket($ticket['token'], $this->stream('mine'), hash('sha256', 'mine'));
        $secondSpace = $this->service->read('space', $secondScope, []);
        $this->assertError(404, fn() => $this->service->agentSpace($this->id()));
        self::assertSame(0, $secondSpace['fileCount']);
        self::assertSame(1, $this->service->read('space', $this->studentScope, [])['fileCount']);
    }

    private function educationScope(): \App\Education\Application\Scope
    {
        return $this->educationAccess->scope($this->teacher, $this->organization, 'education.people.manage');
    }

    private function deviceScope(): \App\DeviceManagement\Application\Scope
    {
        return $this->container->get(\App\DeviceManagement\Application\DeviceAccess::class)->scope($this->teacher, $this->organization);
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
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

    private function account(string $person, string $label, string $role): string
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
            self::fail('Expected Storage request rejection.');
        } catch (StorageError $error) {
            self::assertSame($status, $error->status);
            if ($reason !== null) {
                self::assertSame($reason, $error->reason);
            }
        }
    }
}
