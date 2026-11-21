<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Core\Time\Application\ClockInterface;
use App\DeviceManagement\Application\DeviceAccess;
use App\DeviceManagement\Application\DeviceError;
use App\DeviceManagement\Application\DeviceService;
use App\DeviceManagement\Application\DeviceStore;
use App\DeviceManagement\Application\Scope;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationService;
use App\Education\Application\EducationStore;
use App\Education\Application\FileStore;
use App\Environment;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

final class DeviceManagementTest extends TestCase
{
    private ConnectionInterface $db;
    private ContainerInterface $container;
    private TransactionInterface $transaction;
    private DeviceAccess $access;
    private DeviceStore $store;
    private DeviceService $service;
    private EducationService $education;
    private EducationAccess $educationAccess;
    private EducationStore $educationStore;
    private AuthenticatedIdentity $teacher;
    private Scope $scope;
    private string $organization;

    protected function setUp(): void
    {
        self::assertSame('1', getenv('EDUCATION_ENABLED'), 'Run the dedicated Education suite with its module enabled.');
        $container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $this->container = $container;
        $this->db = $container->get(ConnectionInterface::class);
        TestRuntimeGuard::assertDestructiveOperationsAllowed($this->db);
        $this->transaction = $this->db->beginTransaction();
        foreach (['education', 'device_management'] as $module) {
            $this->db->createCommand(
                "UPDATE core.modules SET enabled=true,enabled_at=now(),disabled_at=NULL WHERE code=:code",
                [':code' => $module],
            )->execute();
        }
        $this->store = $container->get(DeviceStore::class);
        $this->access = $container->get(DeviceAccess::class);
        $this->service = $container->get(DeviceService::class);
        $this->educationAccess = $container->get(EducationAccess::class);
        $educationStore = $container->get(EducationStore::class);
        $this->educationStore = $educationStore;
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-03-08T14:00:00Z');
            }
        };
        $this->education = new EducationService(
            $educationStore,
            $this->educationAccess,
            $clock,
            $container->get(FileStore::class),
            $container->get(\App\Education\Application\Mutation::class),
            $container->get(PermissionService::class),
            $container->get(OrganizationRepository::class),
        );
        $this->organization = (string) $this->db->createCommand("INSERT INTO core.organizations(code,name)
            VALUES(:code,'Device test') RETURNING id::text", [':code' => 'device.test.' . bin2hex(random_bytes(8))])->queryScalar();
        $person = $this->person('Device teacher');
        $this->member($person);
        $account = $this->account($person, 'Device teacher', 'education.teacher');
        $this->educationStore->grantTeacher($this->organization, $account, $person);
        $this->teacher = new AuthenticatedIdentity($account, $person, $this->id(), ['education.teacher'], [], 'en-US', 'UTC');
        $this->scope = $this->access->scope($this->teacher, $this->organization);
    }

    protected function tearDown(): void
    {
        $this->transaction->rollBack();
    }

    public function testDeviceEnrollmentIsSingleUseAndYieldsAgentCredential(): void
    {
        $classroom = $this->service->write('create_classroom', $this->scope, ['name' => 'Room 1']);
        $device = $this->service->write('create_device', $this->scope, ['classroomId' => $classroom['id'], 'name' => 'PC 2', 'number' => '2']);
        self::assertSame('pending', $device['enrollmentState']);
        $enrollment = $this->service->write('device_enroll', $this->scope, [], $device['id']);
        self::assertNotEmpty($enrollment['code']);

        $agent = $this->service->agentEnroll(['code' => $enrollment['code']]);
        self::assertSame($device['id'], $agent['deviceId']);
        self::assertNotEmpty($agent['credential']);
        $this->assertError(409, fn() => $this->service->agentEnroll(['code' => $enrollment['code']]), 'enrollment_invalid');
        $this->assertError(404, fn() => $this->service->agentEnroll(['code' => str_repeat('0', 24)]));

        $detail = $this->service->read('device', $this->scope, [], $device['id']);
        self::assertSame('confirmed', $detail['enrollmentState']);
        self::assertSame($classroom['name'], $detail['classroomName']);
        self::assertSame('Room 1', $this->service->read('classrooms', $this->scope, [])['items'][0]['name']);

        $this->service->agentHeartbeat($device['id'], ['state' => ['cpu' => 12]]);
        $this->service->agentEvent($device['id'], ['kind' => 'boot', 'payload' => ['version' => '1.0']]);
        $detail = $this->service->read('device', $this->scope, [], $device['id']);
        self::assertNotNull($detail['lastSeenAt']);
        self::assertCount(1, $this->service->read('device_events', $this->scope, [], $device['id'])['items']);
    }

    public function testSessionLifecycleTransitionsAndSingleActiveSessionInvariant(): void
    {
        $group = $this->education->write('create_group', $this->educationScope(), ['name' => 'Device group']);
        $person = $this->person('Device student');
        $this->member($person);
        $student = $this->education->write('enroll_student', $this->educationScope(), ['groupId' => $group['id'], 'personId' => $person]);
        $secondPerson = $this->person('Second student');
        $this->member($secondPerson);
        $secondStudent = $this->education->write('enroll_student', $this->educationScope(), ['groupId' => $group['id'], 'personId' => $secondPerson]);

        $device = $this->service->write('create_device', $this->scope, ['name' => 'PC 1', 'number' => '1']);
        $enrollment = $this->service->write('device_enroll', $this->scope, [], $device['id']);
        $agent = $this->service->agentEnroll(['code' => $enrollment['code']]);

        $session = $this->service->write('device_session', $this->scope, ['studentId' => $student['id']], $device['id']);
        self::assertSame('AUTHORIZED', $session['status']);
        self::assertSame($person, $session['studentPersonId']);
        $this->assertError(409, fn() => $this->service->write('device_session', $this->scope, ['studentId' => $secondStudent['id']], $device['id']), 'device_busy');

        $commands = $this->service->agentCommands($device['id']);
        self::assertCount(1, $commands['items']);
        self::assertSame('start_session', $commands['items'][0]['kind']);
        self::assertSame($session['id'], $commands['items'][0]['payload']['sessionId']);
        $commandId = $commands['items'][0]['id'];
        self::assertSame([], $this->service->agentCommands($device['id'])['items']);
        $this->service->agentAcknowledge($device['id'], $commandId, []);
        self::assertTrue($this->service->agentAcknowledge($device['id'], $commandId, [])['acknowledged']);

        foreach (['PREPARING', 'WINDOWS_PROFILE_READY', 'SYNCING_IN', 'ACTIVE'] as $status) {
            $result = $this->service->agentSessionState($device['id'], $session['id'], ['status' => $status]);
            self::assertSame($status, $result['status']);
        }
        $this->assertError(409, fn() => $this->service->agentSessionState($device['id'], $session['id'], ['status' => 'CLEANUP']), 'invalid_transition');
        $this->assertError(422, fn() => $this->service->agentSessionState($device['id'], $session['id'], ['status' => 'NONSENSE']));

        $this->service->write('device_session_end', $this->scope, ['sessionId' => $session['id']], $device['id']);
        $commands = $this->service->agentCommands($device['id']);
        self::assertSame('end_session', $commands['items'][0]['kind']);
        foreach (['ENDING', 'SYNCING_OUT', 'STORAGE_CONFIRMED', 'CLEANUP', 'CLOSED'] as $status) {
            $this->service->agentSessionState($device['id'], $session['id'], ['status' => $status]);
        }
        $next = $this->service->write('device_session', $this->scope, ['studentId' => $secondStudent['id']], $device['id']);
        self::assertSame('AUTHORIZED', $next['status']);
        self::assertNotSame($session['id'], $next['id']);
        self::assertCount(2, $this->service->read('device', $this->scope, [], $device['id'])['sessions']);
    }

    public function testSyncPendingBlocksNextSessionAndRevokedDeviceStops(): void
    {
        $group = $this->education->write('create_group', $this->educationScope(), ['name' => 'Block group']);
        $person = $this->person('Block student');
        $this->member($person);
        $student = $this->education->write('enroll_student', $this->educationScope(), ['groupId' => $group['id'], 'personId' => $person]);
        $device = $this->service->write('create_device', $this->scope, ['name' => 'PC 3', 'number' => '3']);
        $enrollment = $this->service->write('device_enroll', $this->scope, [], $device['id']);
        $agent = $this->service->agentEnroll(['code' => $enrollment['code']]);

        $session = $this->service->write('device_session', $this->scope, ['studentId' => $student['id']], $device['id']);
        foreach (['PREPARING', 'WINDOWS_PROFILE_READY', 'SYNCING_IN', 'ACTIVE', 'ENDING', 'SYNCING_OUT'] as $status) {
            $this->service->agentSessionState($device['id'], $session['id'], ['status' => $status]);
        }
        $this->service->agentSessionState($device['id'], $session['id'], ['status' => 'SYNC_PENDING']);
        $this->assertError(409, fn() => $this->service->write('device_session', $this->scope, ['studentId' => $student['id']], $device['id']), 'sync_pending');
        $this->service->agentSessionState($device['id'], $session['id'], ['status' => 'SYNCING_OUT']);
        $this->service->agentSessionState($device['id'], $session['id'], ['status' => 'STORAGE_CONFIRMED']);
        $this->service->agentSessionState($device['id'], $session['id'], ['status' => 'CLEANUP']);
        $this->service->agentSessionState($device['id'], $session['id'], ['status' => 'CLOSED']);

        $this->service->write('revoke_device', $this->scope, [], $device['id']);
        $this->assertError(409, fn() => $this->service->write('device_session', $this->scope, ['studentId' => $student['id']], $device['id']), 'device_revoked');
        self::assertNull($this->store->credentialDevice(hash('sha256', $agent['credential'])));
    }

    public function testOtherTeacherCannotSeeOrManageForeignDevices(): void
    {
        $device = $this->service->write('create_device', $this->scope, ['name' => 'PC 9', 'number' => '9']);
        $otherPerson = $this->person('Other teacher');
        $this->member($otherPerson);
        $otherAccount = $this->account($otherPerson, 'Other teacher', 'education.teacher');
        $this->educationStore->grantTeacher($this->organization, $otherAccount, $otherPerson);
        $otherIdentity = new AuthenticatedIdentity($otherAccount, $otherPerson, $this->id(), ['education.teacher'], [], 'en-US', 'UTC');
        $otherScope = $this->access->scope($otherIdentity, $this->organization);
        $this->assertError(404, fn() => $this->service->read('device', $otherScope, [], $device['id']));
        $this->assertError(404, fn() => $this->service->write('device_enroll', $otherScope, [], $device['id']));
        self::assertSame([], array_filter(
            $this->service->read('devices', $otherScope, [])['items'],
            static fn(array $row): bool => $row['id'] === $device['id'],
        ));
    }

    private function educationScope(): \App\Education\Application\Scope
    {
        return $this->educationAccess->scope($this->teacher, $this->organization, 'education.people.manage');
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
            self::fail('Expected DeviceManagement request rejection.');
        } catch (DeviceError $error) {
            self::assertSame($status, $error->status);
            if ($reason !== null) {
                self::assertSame($reason, $error->reason);
            }
        }
    }
}
