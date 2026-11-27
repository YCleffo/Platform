<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Activity\Application\ActivityEngineService;
use App\Chat\Application\ChatService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataExporter;
use App\Environment;
use App\Media\Application\MediaError;
use App\Media\Application\MediaScope;
use App\Media\Application\MediaService;
use App\Media\Domain\MediaStatus;
use App\Notifications\Application\NotificationsService;
use App\Shared\Application\HttpError;
use App\Storage\Application\StorageFileAdapter;
use App\Storage\Application\StorageScope;
use App\Storage\Application\StorageService;
use App\Storage\Application\StorageStore;
use App\Workspace\Application\WorkspaceDashboardService;
use App\Workspace\Application\WorkspaceOperationService;
use App\Workspace\Application\WorkspacePreferencesService;
use App\Workspace\Application\WorkspaceSearchService;
use App\Workspace\Application\WorkspaceTrashService;
use App\Workspace\Infrastructure\WorkspaceErasureHandler;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

final class Platform12WorkspaceTest extends TestCase
{
    private ConnectionInterface $db;
    private WorkspaceDashboardService $dashboard;
    private WorkspaceSearchService $search;
    private WorkspaceOperationService $operations;
    private WorkspaceTrashService $trash;
    private WorkspacePreferencesService $preferences;
    private NotificationsService $notifications;
    private MediaService $media;
    private StorageService $storageService;
    private StorageStore $storageStore;
    private StorageFileAdapter $storageAdapter;
    private ChatService $chat;
    private ActivityEngineService $activity;
    private PersonalDataExporter $exporter;
    private WorkspaceErasureHandler $erasureHandler;

    /** @var list<string> */
    private array $createdOrgIds = [];
    /** @var list<string> */
    private array $createdPersonIds = [];
    /** @var list<string> */
    private array $createdAccountIds = [];
    /** @var list<string> */
    private array $createdStorageKeys = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';
        Environment::prepare();
        $runner = new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv());
        $container = $runner->getContainer();

        $this->db = $container->get(ConnectionInterface::class);
        $this->dashboard = $container->get(WorkspaceDashboardService::class);
        $this->search = $container->get(WorkspaceSearchService::class);
        $this->operations = $container->get(WorkspaceOperationService::class);
        $this->trash = $container->get(WorkspaceTrashService::class);
        $this->preferences = $container->get(WorkspacePreferencesService::class);
        $this->notifications = $container->get(NotificationsService::class);
        $this->media = $container->get(MediaService::class);
        $this->storageService = $container->get(StorageService::class);
        $this->storageStore = $container->get(StorageStore::class);
        $this->storageAdapter = $container->get(StorageFileAdapter::class);
        $this->chat = $container->get(ChatService::class);
        $this->activity = $container->get(ActivityEngineService::class);
        $this->exporter = $container->get(PersonalDataExporter::class);
        $this->erasureHandler = $container->get(WorkspaceErasureHandler::class);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->createdStorageKeys as $key) {
            try {
                if ($this->storageAdapter->exists($key)) {
                    $this->storageAdapter->delete($key);
                }
            } catch (\Throwable) {
            }
        }
        foreach ($this->createdOrgIds as $orgId) {
            $this->db->createCommand('DELETE FROM workspace.trash_items WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM workspace.operations WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM workspace.preferences WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM realtime.outbox_events WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM notifications.push_subscriptions WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM notifications.items WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM chat.reactions WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM chat.messages WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM chat.members WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM chat.rooms WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM media.upload_sessions WHERE asset_id IN (SELECT id FROM media.assets WHERE organization_id = CAST(:o AS uuid))', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM media.variants WHERE asset_id IN (SELECT id FROM media.assets WHERE organization_id = CAST(:o AS uuid))', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM media.assets WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM storage.tickets WHERE space_id IN (SELECT id FROM storage.spaces WHERE organization_id = CAST(:o AS uuid))', [':o' => $orgId])->execute();
            $this->db->createCommand('UPDATE storage.files SET current_version_id = NULL WHERE space_id IN (SELECT id FROM storage.spaces WHERE organization_id = CAST(:o AS uuid))', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM storage.versions WHERE file_id IN (SELECT f.id FROM storage.files f JOIN storage.spaces s ON s.id = f.space_id WHERE s.organization_id = CAST(:o AS uuid))', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM storage.files WHERE space_id IN (SELECT id FROM storage.spaces WHERE organization_id = CAST(:o AS uuid))', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM storage.spaces WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM activity.attempts WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM activity.assignments WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM activity.items WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM device_management.devices WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.lessons WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.group_students WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.group_teachers WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.groups WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.parent_accounts WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.student_guardians WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.student_accounts WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.students WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.teachers WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM core.organization_memberships WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM core.organizations WHERE id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
        }
        foreach ($this->createdAccountIds as $aid) {
            $this->db->createCommand('DELETE FROM workspace.preferences WHERE account_id = CAST(:a AS uuid)', [':a' => $aid])->execute();
            $this->db->createCommand('DELETE FROM workspace.operations WHERE account_id = CAST(:a AS uuid)', [':a' => $aid])->execute();
            $this->db->createCommand('DELETE FROM workspace.trash_items WHERE deleted_by_account_id = CAST(:a AS uuid)', [':a' => $aid])->execute();
            $this->db->createCommand('DELETE FROM core.audit_events WHERE actor_account_id = CAST(:a AS uuid)', [':a' => $aid])->execute();
            $this->db->createCommand('DELETE FROM core.auth_sessions WHERE account_id = CAST(:a AS uuid)', [':a' => $aid])->execute();
            $this->db->createCommand('DELETE FROM core.account_roles WHERE account_id = CAST(:a AS uuid)', [':a' => $aid])->execute();
            $this->db->createCommand('DELETE FROM core.accounts WHERE id = CAST(:a AS uuid)', [':a' => $aid])->execute();
        }
        foreach ($this->createdPersonIds as $pid) {
            $this->db->createCommand('DELETE FROM core.persons WHERE id = CAST(:p AS uuid)', [':p' => $pid])->execute();
        }
    }

    private function createOrg(string $suffix): string
    {
        $cleanSuffix = strtolower(preg_replace('/[^a-z0-9._-]+/i', '', $suffix) ?: 'org');
        $orgId = (string) $this->db->createCommand(
            'INSERT INTO core.organizations (code, name) VALUES (:code, :name) RETURNING id::text',
            [':code' => 'ws.' . $cleanSuffix . '.' . bin2hex(random_bytes(4)), ':name' => 'Workspace Org ' . $suffix],
        )->queryScalar();
        $this->createdOrgIds[] = $orgId;
        return $orgId;
    }

    /** @return array{personId: string, accountId: string, sessionId: string, identity: AuthenticatedIdentity} */
    private function createMember(string $orgId, string $name, string $roleCode): array
    {
        $personId = (string) $this->db->createCommand(
            "INSERT INTO core.persons (given_name, family_name, display_name) VALUES (:g, 'Test', :d) RETURNING id::text",
            [':g' => $name, ':d' => $name],
        )->queryScalar();
        $this->createdPersonIds[] = $personId;

        $accountId = (string) $this->db->createCommand(
            "INSERT INTO core.accounts (person_id, account_type, status, label) VALUES (CAST(:p AS uuid), 'human', 'active', :l) RETURNING id::text",
            [':p' => $personId, ':l' => $name],
        )->queryScalar();
        $this->createdAccountIds[] = $accountId;

        $this->db->createCommand(
            'INSERT INTO core.organization_memberships (organization_id, person_id) VALUES (CAST(:o AS uuid), CAST(:p AS uuid))',
            [':o' => $orgId, ':p' => $personId],
        )->execute();

        $this->db->createCommand(
            'INSERT INTO core.account_roles (account_id, role_id) SELECT CAST(:a AS uuid), id FROM core.roles WHERE code = :r ON CONFLICT DO NOTHING',
            [':a' => $accountId, ':r' => $roleCode],
        )->execute();

        $tokenHashHex = bin2hex(random_bytes(32));
        $securityStamp = (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
        $sessionId = (string) $this->db->createCommand(
            "INSERT INTO core.auth_sessions (account_id, session_token_hash, security_stamp, authentication_method, client_type, access_expires_at, expires_at)
             VALUES (CAST(:a AS uuid), decode(:th, 'hex'), CAST(:ss AS uuid), 'password', 'web', now() + interval '1 hour', now() + interval '1 day') RETURNING id::text",
            [':a' => $accountId, ':th' => $tokenHashHex, ':ss' => $securityStamp],
        )->queryScalar();

        /** @var list<string> $perms */
        $perms = $this->db->createCommand(
            'SELECT DISTINCT p.code FROM core.account_roles ar
             JOIN core.role_permissions rp ON rp.role_id = ar.role_id
             JOIN core.permissions p ON p.id = rp.permission_id
             WHERE ar.account_id = CAST(:a AS uuid)',
            [':a' => $accountId],
        )->queryColumn();

        $identity = new AuthenticatedIdentity($accountId, $personId, $sessionId, [$roleCode], $perms, 'ru-RU', 'UTC');
        return ['personId' => $personId, 'accountId' => $accountId, 'sessionId' => $sessionId, 'identity' => $identity];
    }

    public function testDashboardRoleAndModuleFiltering(): void
    {
        $orgId = $this->createOrg('dash');
        $teacher = $this->createMember($orgId, 'Teacher Dash', 'education.teacher');
        $student = $this->createMember($orgId, 'Student Dash', 'education.student');

        $this->db->createCommand(
            'INSERT INTO education.teachers (organization_id, account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))',
            [':o' => $orgId, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
        )->execute();

        $this->db->createCommand(
            'INSERT INTO education.students (organization_id, owner_account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))',
            [':o' => $orgId, ':a' => $teacher['accountId'], ':p' => $student['personId']],
        )->execute();

        $this->db->createCommand(
            "INSERT INTO device_management.devices (organization_id, owner_account_id, owner_person_id, name, number, status)
             VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), 'Lab PC 01', '01', 'ERROR')",
            [':o' => $orgId, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
        )->execute();

        $this->notifications->notify($orgId, $student['personId'], 'homework', 'homework_published', 'New Algebra HW');

        $teacherDash = $this->dashboard->getDashboard($teacher['identity'], $orgId);
        $teacherWidgetIds = array_column($teacherDash['widgets'], 'id');
        self::assertContains('education.overview', $teacherWidgetIds);
        self::assertContains('workspace.operations', $teacherWidgetIds);
        self::assertContains('device_management.alerts', $teacherWidgetIds);
        self::assertSame(1, $teacherDash['summary']['deviceAlerts'] ?? null);

        $studentDash = $this->dashboard->getDashboard($student['identity'], $orgId);
        $studentWidgetIds = array_column($studentDash['widgets'], 'id');
        self::assertContains('notifications.unread', $studentWidgetIds);
        self::assertContains('education.overview', $studentWidgetIds);
        self::assertNotContains('device_management.alerts', $studentWidgetIds);
        self::assertArrayNotHasKey('deviceAlerts', $studentDash['summary']);
        self::assertSame(1, $studentDash['summary']['unreadNotifications']);
    }

    public function testUniversalSearchRuEnAndZeroCrossTenantAndAclLeakage(): void
    {
        $orgA = $this->createOrg('srchA');
        $orgB = $this->createOrg('srchB');
        $teacher = $this->createMember($orgA, 'Преподаватель Алгебры', 'education.teacher');
        $student1 = $this->createMember($orgA, 'Иван Ученик', 'education.student');
        $student2 = $this->createMember($orgA, 'Скрытый Студент', 'education.student');
        $outsiderB = $this->createMember($orgB, 'Внешний Преподаватель', 'education.teacher');

        $this->db->createCommand(
            'INSERT INTO education.teachers (organization_id, account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))',
            [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
        )->execute();

        $s1Id = (string) $this->db->createCommand(
            'INSERT INTO education.students (organization_id, owner_account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text',
            [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $student1['personId']],
        )->queryScalar();
        $this->db->createCommand(
            'INSERT INTO education.students (organization_id, owner_account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))',
            [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $student2['personId']],
        )->execute();

        $groupId = (string) $this->db->createCommand(
            "INSERT INTO education.groups (organization_id, owner_account_id, owner_person_id, name) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), 'Алгебра 8А Quantum') RETURNING id::text",
            [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
        )->queryScalar();
        $this->db->createCommand(
            'INSERT INTO education.group_students (group_id, student_id, organization_id, owner_account_id) VALUES (CAST(:g AS uuid), CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid))',
            [':g' => $groupId, ':s' => $s1Id, ':o' => $orgA, ':a' => $teacher['accountId']],
        )->execute();

        // Secret chat room in Org A where student1 is NOT a member
        $secretRoom = $this->chat->createRoom($teacher['identity'], $orgA, [
            'roomType' => 'group',
            'title' => 'Teacher Secret Quantum Room',
            'memberPersonIds' => [$teacher['personId']],
        ]);
        $this->chat->sendMessage($teacher['identity'], $orgA, (string) $secretRoom['id'], [
            'body' => 'Top secret quantum answer key',
        ]);

        // Draft activity by teacher (must NOT leak to student1)
        $draftAct = $this->activity->createActivity($teacher['identity'], $orgA, [
            'pluginCode' => 'platform-assessment',
            'title' => 'Quantum Exam Draft',
            'description' => 'Hidden exam quantum test',
            'publish' => false,
            'content' => [
                'questions' => [
                    ['id' => 'q1', 'questionType' => 'short_text', 'content' => ['prompt' => '2+2'], 'answerKey' => '4'],
                ],
            ],
        ]);

        // Org B group (must NOT leak to Org A members even in scope=global)
        $this->db->createCommand(
            "INSERT INTO education.groups (organization_id, owner_account_id, owner_person_id, name) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), 'OrgB Quantum Secret') RETURNING id::text",
            [':o' => $orgB, ':a' => $outsiderB['accountId'], ':p' => $outsiderB['personId']],
        )->queryScalar();

        // Russian + typo-tolerant search as teacher
        $ruRes = $this->search->search($teacher['identity'], $orgA, ['q' => 'Алгебра']);
        self::assertGreaterThanOrEqual(1, $ruRes['total']);
        $titles = array_column($ruRes['items'], 'title');
        self::assertContains('Алгебра 8А Quantum', $titles);

        // Typo-tolerant pg_trgm search ('Алгбра' missing 'е')
        $typoRes = $this->search->search($teacher['identity'], $orgA, ['q' => 'Алгбра']);
        self::assertGreaterThanOrEqual(1, $typoRes['total']);

        // Student1 searches 'Quantum' in scope=global -> sees own group, NEVER secret chat room, NEVER draft activity, NEVER OrgB!
        $stuRes = $this->search->search($student1['identity'], $orgA, ['q' => 'Quantum', 'scope' => 'global']);
        $stuTitles = array_column($stuRes['items'], 'title');
        self::assertContains('Алгебра 8А Quantum', $stuTitles);
        self::assertNotContains('Teacher Secret Quantum Room', $stuTitles);
        self::assertNotContains('Quantum Exam Draft', $stuTitles);
        self::assertNotContains('OrgB Quantum Secret', $stuTitles);

        // Student1 searches for student2 by name -> zero leakage!
        $leakRes = $this->search->search($student1['identity'], $orgA, ['q' => 'Скрытый']);
        self::assertSame(0, $leakRes['total']);
        self::assertSame([], $leakRes['items']);

        // Sections search in RU
        $secRes = $this->search->search($student1['identity'], $orgA, ['q' => 'корзина', 'category' => 'sections']);
        self::assertGreaterThanOrEqual(1, $secRes['total']);
    }

    public function testActionModesImmediateReversibleDeferredConfirmedAndSecurityGuard(): void
    {
        $orgId = $this->createOrg('actions');
        $user = $this->createMember($orgId, 'Action User', 'core.admin');

        // 1. Immediate preference set
        $imm = $this->operations->execute($user['identity'], $orgId, [
            'actionKey' => 'workspace.preference.set',
            'mode' => 'immediate',
            'payload' => ['key' => 'theme', 'value' => 'dark'],
        ]);
        self::assertSame('completed', $imm['status']);
        $prefs = $this->preferences->getAll($user['identity'], $orgId);
        self::assertSame('dark', $prefs['preferences']['theme']);

        // 2. Reversible preference set -> Undo within 10s restores previous value!
        $rev = $this->operations->execute($user['identity'], $orgId, [
            'actionKey' => 'workspace.preference.set',
            'mode' => 'reversible',
            'payload' => ['key' => 'theme', 'value' => 'light'],
        ]);
        self::assertSame('undoable', $rev['status']);
        self::assertSame('light', $this->preferences->getAll($user['identity'], $orgId)['preferences']['theme']);

        $undone = $this->operations->undo($user['identity'], $orgId, (string) $rev['id']);
        self::assertSame('undone', $undone['status']);
        self::assertSame('dark', $this->preferences->getAll($user['identity'], $orgId)['preferences']['theme']);

        // 3. Expired undo deadline -> 409 undo_window_expired
        $expiredOp = $this->operations->execute($user['identity'], $orgId, [
            'actionKey' => 'workspace.preference.set',
            'mode' => 'reversible',
            'payload' => ['key' => 'density', 'value' => 'compact'],
        ]);
        $this->db->createCommand(
            "UPDATE workspace.operations SET undo_deadline = now() - interval '2 seconds' WHERE id = CAST(:id AS uuid)",
            [':id' => $expiredOp['id']],
        )->execute();

        try {
            $this->operations->undo($user['identity'], $orgId, (string) $expiredOp['id']);
            self::fail('Expected 409 undo_window_expired.');
        } catch (HttpError $e) {
            self::assertSame(409, $e->status);
            self::assertSame('undo_window_expired', $e->reason);
        }

        // 4. Confirmed mode rejects without confirmDangerous=true
        try {
            $this->operations->execute($user['identity'], $orgId, [
                'actionKey' => 'workspace.preference.set',
                'mode' => 'confirmed',
                'payload' => ['key' => 'danger', 'value' => true],
                'confirmDangerous' => false,
            ]);
            self::fail('Expected 422 confirmation_required.');
        } catch (HttpError $e) {
            self::assertSame(422, $e->status);
            self::assertSame('confirmation_required', $e->reason);
        }

        // 5. Security/auth/finance NEVER deferred or reversible
        foreach (['auth.logout', 'security.session.revoke_all', 'finance.transfer'] as $highRisk) {
            foreach (['reversible', 'deferred'] as $badMode) {
                try {
                    $this->operations->execute($user['identity'], $orgId, [
                        'actionKey' => $highRisk,
                        'mode' => $badMode,
                        'confirmDangerous' => true,
                    ]);
                    self::fail("Expected 422 for {$highRisk} in {$badMode}");
                } catch (HttpError $e) {
                    self::assertSame(422, $e->status);
                    self::assertSame('action_mode_not_allowed', $e->reason);
                }
            }
        }

        // 6. Deferred executes on processDue and can be cancelled before due
        $defCancel = $this->operations->execute($user['identity'], $orgId, [
            'actionKey' => 'workspace.preference.set',
            'mode' => 'deferred',
            'delaySeconds' => 60,
            'payload' => ['key' => 'deferredCancel', 'value' => 1],
        ]);
        self::assertSame('pending', $defCancel['status']);
        $cancelled = $this->operations->cancel($user['identity'], $orgId, (string) $defCancel['id']);
        self::assertSame('cancelled', $cancelled['status']);

        $defDue = $this->operations->execute($user['identity'], $orgId, [
            'actionKey' => 'workspace.preference.set',
            'mode' => 'deferred',
            'delaySeconds' => 10,
            'payload' => ['key' => 'deferredDone', 'value' => 42],
        ]);
        $this->db->createCommand(
            "UPDATE workspace.operations SET execute_at = now() - interval '1 second' WHERE id = CAST(:id AS uuid)",
            [':id' => $defDue['id']],
        )->execute();
        $processed = $this->operations->processDue(20);
        self::assertGreaterThanOrEqual(1, $processed);
        self::assertSame(42, $this->preferences->getAll($user['identity'], $orgId)['preferences']['deferredDone']);
    }

    public function testConcurrentCasBetweenUndoAndProcessDueHasExactOneWinner(): void
    {
        $orgId = $this->createOrg('cas');
        $user = $this->createMember($orgId, 'CAS User', 'core.admin');

        $op = $this->operations->execute($user['identity'], $orgId, [
            'actionKey' => 'workspace.preference.set',
            'mode' => 'reversible',
            'payload' => ['key' => 'casKey', 'value' => 'new'],
        ]);
        $opId = (string) $op['id'];

        // Set undo_deadline ~80ms in the future so undo worker and processDue worker collide right at the deadline
        $this->db->createCommand(
            "UPDATE workspace.operations SET undo_deadline = now() + interval '80 milliseconds' WHERE id = CAST(:id AS uuid)",
            [':id' => $opId],
        )->execute();

        $backendRoot = dirname(__DIR__, 2);
        $undoScript = sprintf(
            "require '%s/vendor/autoload.php'; App\\Environment::prepare(); \$c = (new Yiisoft\\Yii\\Runner\\Http\\HttpApplicationRunner('%s', App\\Environment::appEnv()))->getContainer(); \$svc = \$c->get(App\\Workspace\\Application\\WorkspaceOperationService::class); \$id = new App\\Core\\Auth\\Application\\AuthenticatedIdentity('%s', '%s', '%s', ['core.admin'], ['workspace.use'], 'ru-RU', 'UTC'); usleep(70000); try { \$r = \$svc->undo(\$id, '%s', '%s'); echo 'UNDO_OK:' . \$r['status']; } catch (App\\Shared\\Application\\HttpError \$e) { echo 'UNDO_ERR:' . \$e->reason; }",
            $backendRoot,
            $backendRoot,
            $user['accountId'],
            $user['personId'],
            $user['sessionId'],
            $orgId,
            $opId,
        );
        $commitScript = sprintf(
            "require '%s/vendor/autoload.php'; App\\Environment::prepare(); \$c = (new Yiisoft\\Yii\\Runner\\Http\\HttpApplicationRunner('%s', App\\Environment::appEnv()))->getContainer(); \$svc = \$c->get(App\\Workspace\\Application\\WorkspaceOperationService::class); usleep(85000); \$n = \$svc->processDue(50); echo 'COMMIT_COUNT:' . \$n;",
            $backendRoot,
            $backendRoot,
        );

        $p1 = proc_open(['php', '-r', $undoScript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
        $p2 = proc_open(['php', '-r', $commitScript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);
        self::assertIsResource($p1);
        self::assertIsResource($p2);

        $out1 = stream_get_contents($pipes1[1]);
        $out2 = stream_get_contents($pipes2[1]);
        fclose($pipes1[1]);
        fclose($pipes1[2]);
        fclose($pipes2[1]);
        fclose($pipes2[2]);
        proc_close($p1);
        proc_close($p2);

        $row = $this->db->createCommand(
            'SELECT status, revision, finalized_at::text AS "finalizedAt" FROM workspace.operations WHERE id = CAST(:id AS uuid)',
            [':id' => $opId],
        )->queryOne();

        self::assertIsArray($row);
        self::assertContains($row['status'], ['undone', 'committed']);
        self::assertSame(2, (int) $row['revision']);
        self::assertNotNull($row['finalizedAt']);

        // Also assert strict mutual exclusion in reported outcomes
        if ($row['status'] === 'undone') {
            self::assertSame('UNDO_OK:undone', trim((string) $out1));
        } else {
            self::assertStringContainsString('UNDO_ERR:', (string) $out1);
        }
    }

    public function testUniversalTrashConflictRenameAndS3Purge(): void
    {
        $orgId = $this->createOrg('trash');
        $teacher = $this->createMember($orgId, 'Trash Teacher', 'education.teacher');
        $student = $this->createMember($orgId, 'Trash Student', 'education.student');

        $studentRowId = (string) $this->db->createCommand(
            'INSERT INTO education.students (organization_id, owner_account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text',
            [':o' => $orgId, ':a' => $teacher['accountId'], ':p' => $student['personId']],
        )->queryScalar();

        $scope = new StorageScope($orgId, $student['accountId'], $student['personId'], 'en-US', 'UTC', $studentRowId);

        // 1. Upload real file 1 to SeaweedFS S3 via StorageService
        $content1 = 'Original document bytes v1';
        $sha1 = hash('sha256', $content1);
        $ticket1 = $this->storageService->write('ticket', $scope, [
            'purpose' => 'upload',
            'path' => 'notes/essay-unique.txt',
            'expectedSha256' => $sha1,
            'expectedSize' => strlen($content1),
        ]);
        $stream1 = fopen('php://temp', 'w+b');
        fwrite($stream1, $content1);
        rewind($stream1);
        $this->storageService->uploadWithTicket((string) $ticket1['token'], $stream1, $sha1, null, $scope);
        fclose($stream1);

        $file1 = $this->storageStore->fileByPath($scope, 'notes/essay-unique.txt');
        self::assertNotNull($file1);
        $file1Id = (string) $file1['id'];
        $file1StorageKey = (string) $file1['storageKey'];
        $this->createdStorageKeys[] = $file1StorageKey;
        self::assertTrue($this->storageAdapter->exists($file1StorageKey));

        // Notification pointing to file1 -> available before trash, unavailable after trash
        $notif = $this->notifications->notify(
            $orgId,
            $student['personId'],
            'general',
            'file_shared',
            'Essay uploaded',
            resourceType: 'storage.file',
            resourceId: $file1Id,
        );
        self::assertNotNull($notif);

        // Move file1 to Trash
        $trashedFile = $this->trash->moveToTrash($student['identity'], $orgId, 'storage.file', $file1Id);
        self::assertSame('trashed', $trashedFile['restoreStatus']);
        self::assertNull($this->storageStore->fileByPath($scope, 'notes/essay-unique.txt'));

        // Hidden from Universal Search
        $searchHidden = $this->search->search($student['identity'], $orgId, ['q' => 'essay-unique']);
        self::assertSame(0, $searchHidden['total']);

        // Notification reports resourceAvailable = false
        $notifList = $this->notifications->listNotifications($student['identity'], $orgId);
        self::assertFalse($notifList['items'][0]['resourceAvailable']);

        // 2. Upload replacement file at the same path
        $content2 = 'Replacement document bytes v2';
        $sha2 = hash('sha256', $content2);
        $ticket2 = $this->storageService->write('ticket', $scope, [
            'purpose' => 'upload',
            'path' => 'notes/essay-unique.txt',
            'expectedSha256' => $sha2,
            'expectedSize' => strlen($content2),
        ]);
        $stream2 = fopen('php://temp', 'w+b');
        fwrite($stream2, $content2);
        rewind($stream2);
        $this->storageService->uploadWithTicket((string) $ticket2['token'], $stream2, $sha2, null, $scope);
        fclose($stream2);
        $file2 = $this->storageStore->fileByPath($scope, 'notes/essay-unique.txt');
        self::assertNotNull($file2);
        $this->createdStorageKeys[] = (string) $file2['storageKey'];

        // 3. Restore without renameOnConflict -> 409 restore_conflict with suggestedName
        try {
            $this->trash->restore($student['identity'], $orgId, (string) $trashedFile['id'], false);
            self::fail('Expected 409 restore_conflict.');
        } catch (HttpError $e) {
            self::assertSame(409, $e->status);
            self::assertSame('restore_conflict', $e->reason);
            self::assertSame('path', $e->meta['conflictField'] ?? null);
            self::assertSame((string) $file2['id'], $e->meta['existingId'] ?? null);
            self::assertNotEmpty($e->meta['suggestedName'] ?? null);
        }

        // 4. Restore with renameOnConflict = true -> succeeds and reappears in search!
        $restored = $this->trash->restore($student['identity'], $orgId, (string) $trashedFile['id'], true);
        self::assertTrue($restored['renamed']);
        $searchRestored = $this->search->search($student['identity'], $orgId, ['q' => 'essay-unique']);
        self::assertGreaterThanOrEqual(2, $searchRestored['total']);

        // 5. Media asset with real S3 object -> trash -> deletePermanent -> S3 binary purged!
        $mediaBytes = 'RIFF0000WEBPVPX8';
        $mediaSha = hash('sha256', $mediaBytes);
        $mediaScope = new MediaScope($orgId, $student['accountId'], $student['personId']);
        $reserved = $this->media->reserveUpload($mediaScope, 'image', 'trash-pic.webp', 'image/webp', strlen($mediaBytes), $mediaSha);
        $this->createdStorageKeys[] = $reserved['storageKey'];
        $ms = fopen('php://temp', 'w+b');
        fwrite($ms, $mediaBytes);
        rewind($ms);
        $this->storageAdapter->write($reserved['storageKey'], $ms);
        fclose($ms);
        self::assertTrue($this->storageAdapter->exists($reserved['storageKey']));

        $trashedMedia = $this->trash->moveToTrash($student['identity'], $orgId, 'media.asset', $reserved['assetId']);
        $purged = $this->trash->deletePermanent($student['identity'], $orgId, (string) $trashedMedia['id']);
        self::assertTrue($purged['purged']);
        self::assertFalse($this->storageAdapter->exists($reserved['storageKey']));

        // 6. Draft activity -> trash -> hidden from search -> purge
        $draft = $this->activity->createActivity($teacher['identity'], $orgId, [
            'pluginCode' => 'platform-typing',
            'title' => 'Trashable Typing Draft',
            'publish' => false,
        ]);
        $trashedAct = $this->trash->moveToTrash($teacher['identity'], $orgId, 'activity.definition', (string) $draft['id']);
        self::assertSame(0, $this->search->search($teacher['identity'], $orgId, ['q' => 'Trashable Typing Draft'])['total']);
        $this->trash->deletePermanent($teacher['identity'], $orgId, (string) $trashedAct['id']);
    }

    public function testMediaUploadCancelPurgesS3AndRejectsLateConfirm(): void
    {
        $orgId = $this->createOrg('mediacancel');
        $user = $this->createMember($orgId, 'Media User', 'education.teacher');
        $scope = new MediaScope($orgId, $user['accountId'], $user['personId']);

        $bytes = 'test-audio-cancel-bytes';
        $sha = hash('sha256', $bytes);
        $reserved = $this->media->reserveUpload($scope, 'voice', 'cancel-voice.ogg', 'audio/ogg', strlen($bytes), $sha);
        $assetId = $reserved['assetId'];
        $storageKey = $reserved['storageKey'];
        $this->createdStorageKeys[] = $storageKey;

        // Write binary into live SeaweedFS S3 + register linked workspace operation
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $this->storageAdapter->write($storageKey, $stream);
        fclose($stream);
        self::assertTrue($this->storageAdapter->exists($storageKey));

        $trackedOp = $this->operations->trackOperation($user['identity'], $orgId, [
            'actionKey' => 'media.upload',
            'entityType' => 'media.asset',
            'entityId' => $assetId,
            'status' => 'running',
        ]);

        // Cancel upload
        $cancelled = $this->media->cancelUpload($scope, $assetId);
        self::assertSame(MediaStatus::Cancelled->value, $cancelled['status']);
        self::assertTrue($cancelled['cancelled']);
        self::assertFalse($this->storageAdapter->exists($storageKey));

        // Linked workspace operation marked cancelled
        $opStatus = $this->db->createCommand(
            'SELECT status FROM workspace.operations WHERE id = CAST(:id AS uuid)',
            [':id' => $trackedOp['id']],
        )->queryScalar();
        self::assertSame('cancelled', $opStatus);

        // Late confirmUpload MUST fail with 409 upload_cancelled
        try {
            $this->media->confirmUpload($scope, $assetId);
            self::fail('Expected 409 upload_cancelled.');
        } catch (MediaError $e) {
            self::assertSame(409, $e->status);
            self::assertSame('upload_cancelled', $e->reason);
        }
    }

    public function testNotificationsBulkGroupingDedupAndHonestPushStatus(): void
    {
        $orgId = $this->createOrg('notif');
        $user = $this->createMember($orgId, 'Notif User', 'education.student');

        $n1 = $this->notifications->notify($orgId, $user['personId'], 'homework', 'hw_new', 'HW 1', eventKey: 'ev-1');
        $dup = $this->notifications->notify($orgId, $user['personId'], 'homework', 'hw_new', 'HW 1 Duplicate', eventKey: 'ev-1');
        $n2 = $this->notifications->notify($orgId, $user['personId'], 'system', 'sys_info', 'Sys Info', eventKey: 'ev-2');

        self::assertNotNull($n1);
        self::assertNull($dup);
        self::assertNotNull($n2);

        $listed = $this->notifications->listNotifications($user['identity'], $orgId, ['grouped' => '1']);
        self::assertCount(2, $listed['items']);
        self::assertCount(2, $listed['groups']);
        self::assertFalse($listed['pushStatus']['configured']);
        self::assertSame('not_configured', $listed['pushStatus']['status']);

        $pushStatus = $this->notifications->pushStatus($user['identity'], $orgId);
        self::assertFalse($pushStatus['configured']);
        self::assertSame('vapid_keys_not_configured', $pushStatus['reason']);

        $bulkRead = $this->notifications->bulkRead($user['identity'], $orgId, ['ids' => [$n1['id']]]);
        self::assertSame(1, $bulkRead['updated']);
        self::assertSame(1, $bulkRead['unreadCount']);

        $bulkArchive = $this->notifications->bulkArchive($user['identity'], $orgId, ['ids' => [$n1['id'], $n2['id']]]);
        self::assertSame(2, $bulkArchive['archived']);
        self::assertSame(0, $bulkArchive['unreadCount']);
        self::assertCount(0, $this->notifications->listNotifications($user['identity'], $orgId)['items']);
    }

    public function testWorkspacePrivacyExportAndIdempotentErasure(): void
    {
        $orgId = $this->createOrg('privacy');
        $user = $this->createMember($orgId, 'Privacy User', 'core.admin');

        $this->preferences->set($user['identity'], $orgId, ['key' => 'sidebar', 'value' => 'collapsed']);
        $this->operations->execute($user['identity'], $orgId, [
            'actionKey' => 'workspace.preference.set',
            'mode' => 'immediate',
            'payload' => ['key' => 'localeHint', 'value' => 'ru'],
        ]);

        $reqId = (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
        $export = $this->exporter->export(new PersonalDataExportContext(
            $user['personId'],
            $reqId,
            $user['personId'],
            $user['accountId'],
            null,
            new DateTimeImmutable(),
        ));
        $sectionsByCode = [];
        foreach ($export->sections as $section) {
            $sectionsByCode[$section->contributorCode] = $section;
        }
        self::assertArrayHasKey('workspace.export', $sectionsByCode);
        self::assertNotEmpty($sectionsByCode['workspace.export']->records);

        $itemId = (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
        self::assertTrue($this->erasureHandler->owns($user['personId'], $user['personId']));
        $res1 = $this->erasureHandler->execute($user['personId'], $user['personId'], 'delete', $itemId);
        $res2 = $this->erasureHandler->execute($user['personId'], $user['personId'], 'delete', $itemId);
        self::assertSame('deleted', $res1);
        self::assertSame('deleted', $res2);
        self::assertSame('deleted', $this->erasureHandler->reconcile($itemId));

        $prefCount = (int) $this->db->createCommand(
            'SELECT count(*) FROM workspace.preferences WHERE account_id = CAST(:a AS uuid)',
            [':a' => $user['accountId']],
        )->queryScalar();
        $opCount = (int) $this->db->createCommand(
            'SELECT count(*) FROM workspace.operations WHERE account_id = CAST(:a AS uuid)',
            [':a' => $user['accountId']],
        )->queryScalar();
        self::assertSame(0, $prefCount);
        self::assertSame(0, $opCount);

        $this->db->createCommand('DELETE FROM workspace.privacy_receipts WHERE item_id = CAST(:i AS uuid)', [':i' => $itemId])->execute();
    }
}
