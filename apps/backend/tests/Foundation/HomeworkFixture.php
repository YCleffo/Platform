<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Core\Time\Application\ClockInterface;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\EducationService;
use App\Education\Application\EducationStore;
use App\Education\Application\HomeworkService;
use App\Education\Application\Scope;
use App\Environment;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

/** Shared fixtures create only transactional records in the guarded isolated database. */
abstract class HomeworkFixture extends TestCase
{
    protected ConnectionInterface $db;
    protected ContainerInterface $container;
    private ?TransactionInterface $transaction = null;
    protected EducationService $education;
    protected EducationStore $educationStore;
    protected EducationAccess $access;
    protected HomeworkService $homework;
    protected HomeworkClock $clock;
    protected Scope $teacher;
    protected Scope $colleague;
    protected Scope $outsider;
    protected array $group;
    protected array $learner;
    protected array $peer;

    protected function setUp(): void
    {
        self::assertSame('1', getenv('EDUCATION_ENABLED'), 'Enable Education only in the dedicated isolated runtime.');
        $this->container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
        $this->db = $this->container->get(ConnectionInterface::class);
        TestRuntimeGuard::assertDestructiveOperationsAllowed($this->db);
        $this->transaction = $this->db->beginTransaction();
        $this->db->createCommand("UPDATE core.modules SET enabled=true,enabled_at=now(),disabled_at=NULL WHERE code='education'")->execute();
        $this->education = $this->container->get(EducationService::class);
        $this->educationStore = $this->container->get(EducationStore::class);
        $this->access = $this->container->get(EducationAccess::class);
        $this->clock = new HomeworkClock();
        $this->homework = $this->withClock(HomeworkService::class);
        $organization = $this->organization();
        $this->teacher = $this->teacher($organization);
        $this->colleague = $this->teacher($organization);
        $this->outsider = $this->teacher($this->organization());
        $this->group = $this->education->write('create_group', $this->teacher, ['name' => 'Homework group']);
        $this->learner = $this->student('Learner', $this->teacher, $this->group['id']);
        $this->peer = $this->student('Peer', $this->teacher, $this->group['id']);
    }

    protected function tearDown(): void
    {
        $this->transaction?->rollBack();
    }

    /** Resolve real production collaborators, replacing only the explicitly injected clock. */
    protected function withClock(string $class, array $overrides = []): object
    {
        $reflection = new \ReflectionClass($class);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new \LogicException('Unresolvable test collaborator ' . $parameter->getName());
            }
            $arguments[] = $overrides[$type->getName()] ?? ($type->getName() === ClockInterface::class ? $this->clock : $this->container->get($type->getName()));
        }
        return $reflection->newInstanceArgs($arguments);
    }

    protected function draft(array $overrides = []): array
    {
        return $this->homework->write('create', $this->teacher, array_replace([
            'title' => 'Explain a loop', 'description' => 'Homework description', 'instructions' => 'Use an example.',
            'type' => 'text', 'groupId' => $this->group['id'], 'studentIds' => [],
            'dueAt' => '2026-11-14T12:00:00Z', 'allowLate' => false,
        ], $overrides))['assignment'];
    }

    protected function publish(array $overrides = []): array
    {
        $draft = $this->draft($overrides);
        return $this->homework->write('publish', $this->teacher, ['version' => $draft['version']], $draft['id'])['assignment'];
    }

    protected function detail(string $assignmentId, ?Scope $student = null): array
    {
        return $this->homework->read('student_detail', $student ?? $this->learner['scope'], [], $assignmentId);
    }

    protected function answer(string $assignmentId, string $text = 'A loop repeats instructions.', ?Scope $student = null): array
    {
        $student ??= $this->learner['scope'];
        $detail = $this->detail($assignmentId, $student);
        return $this->homework->write('save_draft', $student, ['version' => $detail['submission']['version'], 'text' => $text], $assignmentId);
    }

    protected function submit(string $assignmentId, ?Scope $student = null, ?string $key = null): array
    {
        $student ??= $this->learner['scope'];
        $detail = $this->detail($assignmentId, $student);
        return $this->homework->write('submit', $student, ['version' => $detail['submission']['version'], 'idempotencyKey' => $key ?? $this->id()], $assignmentId);
    }

    protected function student(string $name, Scope $teacher, string $groupId): array
    {
        $person = $this->person($name);
        $this->member($teacher->organizationId, $person);
        $student = $this->education->write('enroll_student', $teacher, ['groupId' => $groupId, 'personId' => $person]);
        $account = $this->account($person, 'education.student');
        $identity = new AuthenticatedIdentity($account, $person, $this->id(), [], [], 'en-US', 'UTC');
        $invite = $this->education->write('invite_student', $teacher, [], $student['id']);
        $this->education->activateInvite($identity, $person, ['code' => $invite['code']]);
        return [...$student, 'personId' => $person, 'accountId' => $account, 'identity' => $identity,
            'scope' => $this->access->studentScope($identity, $teacher->organizationId, 'education.student.use')];
    }

    protected function teacher(string $organization): Scope
    {
        $person = $this->person('Homework teacher');
        $this->member($organization, $person);
        $account = $this->account($person, 'education.teacher');
        $this->educationStore->grantTeacher($organization, $account, $person);
        return $this->access->scope(new AuthenticatedIdentity($account, $person, $this->id(), [], [], 'en-US', 'UTC'), $organization, 'education.access');
    }

    protected function organization(): string
    {
        return (string) $this->db->createCommand(
            "INSERT INTO core.organizations(code,name) VALUES(:code,'Homework test') RETURNING id::text",
            [':code' => 'homework.test.' . bin2hex(random_bytes(8))],
        )->queryScalar();
    }

    private function person(string $name): string
    {
        return (string) $this->db->createCommand("INSERT INTO core.persons(given_name,family_name) VALUES(:name,'QA') RETURNING id::text", [':name' => $name])->queryScalar();
    }

    private function member(string $organization, string $person): void
    {
        $this->db->createCommand(
            'INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))',
            [':org' => $organization, ':person' => $person],
        )->execute();
    }

    private function account(string $person, string $role): string
    {
        $account = (string) $this->db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label) VALUES(CAST(:person AS uuid),'human','active','Homework QA') RETURNING id::text", [':person' => $person])->queryScalar();
        $this->db->createCommand('INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:id AS uuid),id FROM core.roles WHERE code=:role', [':id' => $account, ':role' => $role])->execute();
        return $account;
    }

    protected function id(): string
    {
        return (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
    }

    protected function rejection(int|array $status, callable $action, ?string $reason = null): void
    {
        try {
            $action();
            self::fail('Expected the Education request to be rejected.');
        } catch (EducationError $error) {
            self::assertContains($error->status, (array) $status);
            if ($reason !== null) {
                self::assertSame($reason, $error->reason);
            }
        }
    }

    protected function rowCount(string $table, string $column, string $id): int
    {
        // Table/column names are literals authored by these tests, never request data.
        return (int) $this->db->createCommand('SELECT count(*) FROM education.' . $table . ' WHERE ' . $column . '=CAST(:id AS uuid)', [':id' => $id])->queryScalar();
    }

    protected function sameResponse(array $expected, array $actual): void
    {
        // JSON object property order is immaterial; [] versus {} remains a contract difference.
        self::assertEquals(
            json_decode(json_encode($expected, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR),
            json_decode(json_encode($actual, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR),
        );
    }
}

final class HomeworkClock implements ClockInterface
{
    public DateTimeImmutable $instant;

    public function __construct()
    {
        $this->instant = new DateTimeImmutable('2026-11-13T12:00:00Z');
    }
    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
