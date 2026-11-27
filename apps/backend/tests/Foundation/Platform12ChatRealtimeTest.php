<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Chat\Application\ChatService;
use App\Chat\Infrastructure\DefaultChatContextPolicy;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Education\Infrastructure\EducationChatBridge;
use App\Education\Infrastructure\EducationChatContextPolicy;
use App\Environment;
use App\Notifications\Application\NotificationsService;
use App\Realtime\Application\RealtimeChannelAuthorizerInterface;
use App\Realtime\Application\RealtimeService;
use App\Realtime\Infrastructure\DefaultRealtimeChannelAuthorizer;
use App\Realtime\Infrastructure\WebSocketServer;
use App\Shared\Application\HttpError;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

final class Platform12ChatRealtimeTest extends TestCase
{
    private ConnectionInterface $db;
    private ClientInterface $redis;
    private OrganizationRepository $organizations;
    private PermissionService $permissions;
    private RealtimeChannelAuthorizerInterface $authorizer;
    private RealtimeService $realtime;
    private ChatService $chat;
    private EducationChatBridge $bridge;
    private EducationChatContextPolicy $educationPolicy;

    /** @var list<string> */
    private array $createdOrgIds = [];
    /** @var list<string> */
    private array $createdPersonIds = [];
    /** @var list<string> */
    private array $createdAccountIds = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/bootstrap.php';
        Environment::prepare();
        $runner = new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv());
        $container = $runner->getContainer();

        $this->db = $container->get(ConnectionInterface::class);
        $this->redis = $container->get(ClientInterface::class);
        $this->organizations = $container->get(OrganizationRepository::class);
        $this->permissions = $container->get(PermissionService::class);
        $this->authorizer = $container->get(RealtimeChannelAuthorizerInterface::class);
        $this->realtime = $container->get(RealtimeService::class);
        $this->educationPolicy = $container->get(EducationChatContextPolicy::class);
        $this->bridge = new EducationChatBridge($this->db, $this->realtime);

        $notifications = $container->get(NotificationsService::class);
        $this->chat = new ChatService(
            $this->db,
            $this->organizations,
            $this->permissions,
            $this->realtime,
            $notifications,
            $this->educationPolicy,
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->createdOrgIds as $orgId) {
            $this->db->createCommand('DELETE FROM realtime.outbox_events WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM notifications.items WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM chat.reactions WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM chat.messages WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM chat.members WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM chat.rooms WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM activity.attempts WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM activity.assignments WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM activity.items WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.notifications WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.messages WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.thread_members WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
            $this->db->createCommand('DELETE FROM education.threads WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->execute();
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
        $orgId = (string) $this->db->createCommand(
            'INSERT INTO core.organizations (code, name) VALUES (:code, :name) RETURNING id::text',
            [':code' => 'p12.' . $suffix . '.' . bin2hex(random_bytes(4)), ':name' => 'P12 Org ' . $suffix],
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

        $identity = new AuthenticatedIdentity($accountId, $personId, $sessionId, [], [], 'en-US', 'UTC');
        return ['personId' => $personId, 'accountId' => $accountId, 'sessionId' => $sessionId, 'identity' => $identity];
    }

    public function testGate04EducationChatIsolationAndMembershipRevocation(): void
    {
        $enabled = (bool) $this->db->createCommand("SELECT enabled FROM core.modules WHERE code = 'education'")->queryScalar();
        if (!$enabled) {
            $this->markTestSkipped('Education module is disabled in this environment.');
        }

        $orgId = $this->createOrg('gate04');
        $teacher1 = $this->createMember($orgId, 'Teacher One', 'education.teacher');
        $teacher2 = $this->createMember($orgId, 'Teacher Two', 'education.teacher');
        $student1 = $this->createMember($orgId, 'Student One', 'education.student');
        $student2 = $this->createMember($orgId, 'Student Two', 'education.student');
        $parent1 = $this->createMember($orgId, 'Parent One', 'education.parent');
        $parent2 = $this->createMember($orgId, 'Parent Two', 'education.parent');
        $arbitraryMember = $this->createMember($orgId, 'Arbitrary Org Member', 'education.student');

        $this->db->createCommand(
            'INSERT INTO education.teachers (organization_id, account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))',
            [':o' => $orgId, ':a' => $teacher1['accountId'], ':p' => $teacher1['personId']],
        )->execute();
        $this->db->createCommand(
            'INSERT INTO education.teachers (organization_id, account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))',
            [':o' => $orgId, ':a' => $teacher2['accountId'], ':p' => $teacher2['personId']],
        )->execute();

        $s1Id = (string) $this->db->createCommand(
            'INSERT INTO education.students (organization_id, owner_account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text',
            [':o' => $orgId, ':a' => $teacher1['accountId'], ':p' => $student1['personId']],
        )->queryScalar();
        $s2Id = (string) $this->db->createCommand(
            'INSERT INTO education.students (organization_id, owner_account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text',
            [':o' => $orgId, ':a' => $teacher2['accountId'], ':p' => $student2['personId']],
        )->queryScalar();

        $group1Id = (string) $this->db->createCommand(
            "INSERT INTO education.groups (organization_id, owner_account_id, owner_person_id, name) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), 'Group 1') RETURNING id::text",
            [':o' => $orgId, ':a' => $teacher1['accountId'], ':p' => $teacher1['personId']],
        )->queryScalar();
        $this->db->createCommand(
            'INSERT INTO education.group_students (group_id, student_id, organization_id, owner_account_id) VALUES (CAST(:g AS uuid), CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid))',
            [':g' => $group1Id, ':s' => $s1Id, ':o' => $orgId, ':a' => $teacher1['accountId']],
        )->execute();

        $group2Id = (string) $this->db->createCommand(
            "INSERT INTO education.groups (organization_id, owner_account_id, owner_person_id, name) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), 'Group 2') RETURNING id::text",
            [':o' => $orgId, ':a' => $teacher2['accountId'], ':p' => $teacher2['personId']],
        )->queryScalar();
        $this->db->createCommand(
            'INSERT INTO education.group_students (group_id, student_id, organization_id, owner_account_id) VALUES (CAST(:g AS uuid), CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid))',
            [':g' => $group2Id, ':s' => $s2Id, ':o' => $orgId, ':a' => $teacher2['accountId']],
        )->execute();

        $this->db->createCommand(
            "INSERT INTO education.student_guardians (organization_id, student_id, guardian_person_id, relationship, status, confirmed_at, confirmed_by_account_id)
             VALUES (CAST(:o AS uuid), CAST(:s AS uuid), CAST(:p AS uuid), 'mother', 'confirmed', now(), CAST(:c AS uuid))",
            [':o' => $orgId, ':s' => $s1Id, ':p' => $parent1['personId'], ':c' => $teacher1['accountId']],
        )->execute();
        $this->db->createCommand(
            'INSERT INTO education.parent_accounts (organization_id, guardian_person_id, account_id, activated_at)
             VALUES (CAST(:o AS uuid), CAST(:p AS uuid), CAST(:a AS uuid), now())',
            [':o' => $orgId, ':p' => $parent1['personId'], ':a' => $parent1['accountId']],
        )->execute();

        $this->db->createCommand(
            "INSERT INTO education.student_guardians (organization_id, student_id, guardian_person_id, relationship, status, confirmed_at, confirmed_by_account_id)
             VALUES (CAST(:o AS uuid), CAST(:s AS uuid), CAST(:p AS uuid), 'father', 'confirmed', now(), CAST(:c AS uuid))",
            [':o' => $orgId, ':s' => $s2Id, ':p' => $parent2['personId'], ':c' => $teacher2['accountId']],
        )->execute();
        $this->db->createCommand(
            'INSERT INTO education.parent_accounts (organization_id, guardian_person_id, account_id, activated_at)
             VALUES (CAST(:o AS uuid), CAST(:p AS uuid), CAST(:a AS uuid), now())',
            [':o' => $orgId, ':p' => $parent2['personId'], ':a' => $parent2['accountId']],
        )->execute();

        // 1. Teacher1 sees colleague Teacher2, Student1, Parent1 — NOT Student2, NOT Parent2, NOT ArbitraryMember
        $t1Contacts = array_column($this->educationPolicy->listAvailableContacts($teacher1['identity'], $orgId), 'personId');
        $this->assertContains($teacher2['personId'], $t1Contacts);
        $this->assertContains($student1['personId'], $t1Contacts);
        $this->assertContains($parent1['personId'], $t1Contacts);
        $this->assertNotContains($student2['personId'], $t1Contacts);
        $this->assertNotContains($parent2['personId'], $t1Contacts);
        $this->assertNotContains($arbitraryMember['personId'], $t1Contacts);

        // 2. Student1 sees ONLY Teacher1 — NOT Student2, NOT Teacher2, NOT Parent1, NOT ArbitraryMember
        $s1Contacts = array_column($this->educationPolicy->listAvailableContacts($student1['identity'], $orgId), 'personId');
        $this->assertSame([$teacher1['personId']], $s1Contacts);
        $this->assertFalse($this->educationPolicy->canCreateRoomWith($student1['identity'], $orgId, 'direct', [$student2['personId']]));
        $this->assertFalse($this->educationPolicy->canCreateRoomWith($student1['identity'], $orgId, 'direct', [$teacher2['personId']]));
        $this->assertFalse($this->educationPolicy->canCreateRoomWith($student1['identity'], $orgId, 'direct', [$arbitraryMember['personId']]));
        $this->assertTrue($this->educationPolicy->canCreateRoomWith($student1['identity'], $orgId, 'direct', [$teacher1['personId']]));

        // 3. Parent1 sees ONLY Teacher1 — NOT Teacher2, NOT Parent2, NOT Student2
        $p1Contacts = array_column($this->educationPolicy->listAvailableContacts($parent1['identity'], $orgId), 'personId');
        $this->assertSame([$teacher1['personId']], $p1Contacts);
        $this->assertFalse($this->educationPolicy->canCreateRoomWith($parent1['identity'], $orgId, 'direct', [$teacher2['personId']]));
        $this->assertFalse($this->educationPolicy->canCreateRoomWith($parent1['identity'], $orgId, 'direct', [$parent2['personId']]));

        // 4. Arbitrary non-teacher/non-enrolled org member sees 0 contacts and cannot create rooms
        $arbContacts = $this->educationPolicy->listAvailableContacts($arbitraryMember['identity'], $orgId);
        $this->assertSame([], $arbContacts);
        $this->assertFalse($this->educationPolicy->canCreateRoomWith($arbitraryMember['identity'], $orgId, 'direct', [$teacher1['personId']]));

        // 5. Room creation, messaging, membership removal & immediate revocation
        $room = $this->chat->createRoom($teacher1['identity'], $orgId, [
            'roomType' => 'group',
            'title' => 'Math Study',
            'memberPersonIds' => [$student1['personId'], $teacher2['personId']],
            'body' => 'Hello group!',
        ]);
        $roomId = (string) $room['id'];

        $this->assertTrue($this->authorizer->canSubscribe($student1['accountId'], $student1['personId'], $orgId, 'room:' . $roomId));
        $this->chat->removeMember($teacher1['identity'], $orgId, $roomId, $student1['personId']);

        // Student1 access is immediately revoked
        $this->assertFalse($this->authorizer->canSubscribe($student1['accountId'], $student1['personId'], $orgId, 'room:' . $roomId));
        $removedEventCount = (int) $this->db->createCommand(
            "SELECT count(*) FROM realtime.outbox_events
             WHERE organization_id = CAST(:o AS uuid)
               AND channel = :ch
               AND event_type = 'chat.member.removed'",
            [':o' => $orgId, ':ch' => 'room:' . $roomId],
        )->queryScalar();
        $this->assertGreaterThanOrEqual(1, $removedEventCount);

        try {
            $this->chat->sendMessage($student1['identity'], $orgId, $roomId, ['body' => 'Should fail']);
            $this->fail('Removed member must not be able to send messages');
        } catch (HttpError $e) {
            $this->assertSame(404, $e->status);
        }

        // 6. EducationChatBridge syncRoomMembership & revokeMemberAccess
        $this->bridge->syncRoomMembership($orgId, $roomId, [$teacher1['personId'], $student1['personId']], $teacher1['accountId'], $teacher1['personId']);
        $this->assertTrue($this->authorizer->canSubscribe($student1['accountId'], $student1['personId'], $orgId, 'room:' . $roomId));
        $this->assertFalse($this->authorizer->canSubscribe($teacher2['accountId'], $teacher2['personId'], $orgId, 'room:' . $roomId));

        $this->assertTrue($this->bridge->revokeMemberAccess($orgId, $roomId, $student1['personId'], $teacher1['accountId'], $teacher1['personId']));
        $this->assertFalse($this->authorizer->canSubscribe($student1['accountId'], $student1['personId'], $orgId, 'room:' . $roomId));
    }

    public function testGate04LegacyBackfillSqlPreservesUuidsAndReadCursorsIdempotently(): void
    {
        $orgId = $this->createOrg('backfill');
        $u1 = $this->createMember($orgId, 'U1', 'education.teacher');
        $u2 = $this->createMember($orgId, 'U2', 'education.student');

        $threadId = (string) $this->db->createCommand(
            "INSERT INTO education.threads (organization_id, subject) VALUES (CAST(:o AS uuid), 'Legacy Thread') RETURNING id::text",
            [':o' => $orgId],
        )->queryScalar();
        $msgId = (string) $this->db->createCommand(
            "INSERT INTO education.messages (thread_id, organization_id, sender_person_id, body)
             VALUES (CAST(:t AS uuid), CAST(:o AS uuid), CAST(:p AS uuid), 'Legacy message body') RETURNING id::text",
            [':t' => $threadId, ':o' => $orgId, ':p' => $u1['personId']],
        )->queryScalar();
        $this->db->createCommand(
            'INSERT INTO education.thread_members (thread_id, organization_id, person_id) VALUES (CAST(:t AS uuid), CAST(:o AS uuid), CAST(:p AS uuid))',
            [':t' => $threadId, ':o' => $orgId, ':p' => $u1['personId']],
        )->execute();
        $this->db->createCommand(
            'INSERT INTO education.thread_members (thread_id, organization_id, person_id, last_read_at, last_read_message_id)
             VALUES (CAST(:t AS uuid), CAST(:o AS uuid), CAST(:p AS uuid), now(), CAST(:m AS uuid))',
            [':t' => $threadId, ':o' => $orgId, ':p' => $u2['personId'], ':m' => $msgId],
        )->execute();

        $sql = file_get_contents(dirname(__DIR__, 2) . '/migrations/chat/002_chat_consolidation.sql');
        $this->assertIsString($sql);
        $this->assertInstanceOf(\Yiisoft\Db\Driver\Pdo\PdoConnectionInterface::class, $this->db);
        $this->db->getActivePdo()->exec($sql);
        // Run twice to prove idempotency
        $this->db->getActivePdo()->exec($sql);

        $roomExists = $this->db->createCommand('SELECT title FROM chat.rooms WHERE id = CAST(:id AS uuid)', [':id' => $threadId])->queryScalar();
        $this->assertSame('Legacy Thread', $roomExists);
        $msgBody = $this->db->createCommand('SELECT body FROM chat.messages WHERE id = CAST(:id AS uuid)', [':id' => $msgId])->queryScalar();
        $this->assertSame('Legacy message body', $msgBody);
        $lastReadId = $this->db->createCommand(
            'SELECT last_read_message_id::text FROM chat.members WHERE room_id = CAST(:r AS uuid) AND person_id = CAST(:p AS uuid)',
            [':r' => $threadId, ':p' => $u2['personId']],
        )->queryScalar();
        $this->assertSame($msgId, $lastReadId);
    }

    public function testGate05ChannelAuthorizerAndSessionRevocationAndWebSocketLimits(): void
    {
        $orgId = $this->createOrg('gate05');
        $teacher = $this->createMember($orgId, 'Author Teacher', 'education.teacher');
        $assignedStudent = $this->createMember($orgId, 'Assigned Student', 'education.student');
        $otherStudent = $this->createMember($orgId, 'Other Student', 'education.student');

        $activityId = (string) $this->db->createCommand(
            "INSERT INTO activity.items (organization_id, plugin_code, author_person_id, title, status)
             VALUES (CAST(:o AS uuid), 'platform-typing', CAST(:p AS uuid), 'Typing Test', 'PUBLISHED') RETURNING id::text",
            [':o' => $orgId, ':p' => $teacher['personId']],
        )->queryScalar();
        $versionId = (string) $this->db->createCommand(
            "INSERT INTO activity.versions (activity_id, version_number, title)
             VALUES (CAST(:a AS uuid), 1, 'v1') RETURNING id::text",
            [':a' => $activityId],
        )->queryScalar();
        $this->db->createCommand(
            "INSERT INTO activity.assignments (organization_id, activity_id, version_id, assigned_by_person_id, target_type, target_id, status)
             VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:v AS uuid), CAST(:by AS uuid), 'person', CAST(:target AS uuid), 'ACTIVE')",
            [':o' => $orgId, ':a' => $activityId, ':v' => $versionId, ':by' => $teacher['personId'], ':target' => $assignedStudent['personId']],
        )->execute();

        // Author and assigned student allowed; arbitrary other student DENIED
        $this->assertTrue($this->authorizer->canSubscribe($teacher['accountId'], $teacher['personId'], $orgId, 'activity:' . $activityId));
        $this->assertTrue($this->authorizer->canSubscribe($assignedStudent['accountId'], $assignedStudent['personId'], $orgId, 'activity:' . $activityId));
        $this->assertFalse($this->authorizer->canSubscribe($otherStudent['accountId'], $otherStudent['personId'], $orgId, 'activity:' . $activityId));
        $this->assertFalse($this->authorizer->canSubscribe($otherStudent['accountId'], $otherStudent['personId'], $orgId, 'assessment:' . $activityId));

        // Session revocation check
        $this->assertTrue($this->realtime->isConnectionStillAuthorized($assignedStudent['accountId'], $assignedStudent['personId'], $assignedStudent['sessionId'], $orgId));
        $this->db->createCommand('UPDATE core.auth_sessions SET revoked_at = now() WHERE id = CAST(:s AS uuid)', [':s' => $assignedStudent['sessionId']])->execute();
        $this->assertFalse($this->realtime->isConnectionStillAuthorized($assignedStudent['accountId'], $assignedStudent['personId'], $assignedStudent['sessionId'], $orgId));

        // WebSocketServer limits & RFC 6455 close 1009 / 4001 over socket pair
        $this->assertSame(2000, WebSocketServer::MAX_CLIENTS);
        $this->assertSame(16384, WebSocketServer::MAX_HANDSHAKE_BYTES);
        $this->assertSame(65536, WebSocketServer::MAX_FRAME_BYTES);
        $this->assertSame(2097152, WebSocketServer::MAX_WRITE_BUFFER_BYTES);
        $this->assertSame(200, WebSocketServer::MAX_CHANNELS_PER_CLIENT);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$serverSock, $clientSock] = $pair;
        stream_set_blocking($serverSock, false);
        stream_set_blocking($clientSock, false);

        $wsServer = new WebSocketServer($this->realtime, $this->authorizer);
        $clientsProp = new ReflectionProperty(WebSocketServer::class, 'clients');
        $cid = (int) $serverSock;
        $clientsProp->setValue($wsServer, [
            $cid => [
                'socket' => $serverSock,
                'handshaked' => true,
                'buffer' => '',
                'writeBuffer' => '',
                'accountId' => $teacher['accountId'],
                'personId' => $teacher['personId'],
                'sessionId' => $teacher['sessionId'],
                'org' => $orgId,
                'channels' => ['org:' . $orgId => true, 'user:' . $teacher['personId'] => true],
                'seenSeq' => [],
                'lastPingAt' => microtime(true),
                'lastPongAt' => microtime(true),
                'lastAuthCheckAt' => microtime(true),
            ],
        ]);

        // Simulate auth.session.revoked event -> must emit error + close 4001 and drop client
        $dispatchMethod = new ReflectionMethod(WebSocketServer::class, 'dispatchEventToClients');
        $dispatchMethod->invoke($wsServer, [
            'organizationId' => $orgId,
            'channel' => 'user:' . $teacher['personId'],
            'eventType' => 'auth.session.revoked',
            'seq' => 0,
            'payload' => ['sessionId' => $teacher['sessionId'], 'accountId' => $teacher['accountId']],
        ]);

        $this->assertArrayNotHasKey($cid, $clientsProp->getValue($wsServer));
        $rawOut = (string) stream_get_contents($clientSock);
        $this->assertStringContainsString('session_revoked', $rawOut);
        $this->assertStringContainsString(pack('n', 4001), $rawOut);
        @fclose($clientSock);
    }

    public function testGate06TransactionalOutboxLeaseBackoffDeadLetterAndOutOfOrderCommits(): void
    {
        $orgId = $this->createOrg('gate06');

        // Mock Redis that throws on publish
        $failingRedis = $this->createMock(ClientInterface::class);
        $failingRedis->method('__call')->willReturnCallback(static function (string $name): mixed {
            if ($name === 'publish') {
                throw new RuntimeException('redis_unavailable');
            }
            return null;
        });

        $failingRealtime = new RealtimeService($this->db, $failingRedis, $this->authorizer, 'test-secret');

        // Run inside an outer transaction (nested savepoints) so the live platform-backend-realtime daemon
        // on its separate DB connection cannot race our deterministic failure/out-of-order assertions.
        $tx = $this->db->beginTransaction();
        try {
            $ev = $failingRealtime->publish($orgId, 'org:' . $orgId, 'test.outbox', ['k' => 'v']);

            // Set max_attempts = 2 for this test row so we can verify backoff AND dead-lettering
            $this->db->createCommand('UPDATE realtime.outbox_events SET max_attempts = 2 WHERE seq = :s', [':s' => $ev->seq])->execute();

            // Attempt 1 fails -> dispatched_at MUST stay NULL, locked_until NULL, next_retry_at > now(), dead_lettered_at NULL
            $relayed = $failingRealtime->relayPending(50);
            $this->assertSame(0, $relayed);
            $row1 = $this->db->createCommand(
                'SELECT dispatched_at, locked_until, attempts, next_retry_at, dead_lettered_at, last_error
                 FROM realtime.outbox_events WHERE seq = :s',
                [':s' => $ev->seq],
            )->queryOne();
            $this->assertIsArray($row1);
            $this->assertNull($row1['dispatched_at']);
            $this->assertNull($row1['locked_until']);
            $this->assertSame(1, (int) $row1['attempts']);
            $this->assertNotNull($row1['next_retry_at']);
            $this->assertNull($row1['dead_lettered_at']);
            $this->assertStringContainsString('redis_unavailable', (string) $row1['last_error']);

            // Fast-forward next_retry_at -> Attempt 2 hits max_attempts=2 -> dead_lettered_at is set!
            $this->db->createCommand("UPDATE realtime.outbox_events SET next_retry_at = now() - interval '1 second' WHERE seq = :s", [':s' => $ev->seq])->execute();
            $failingRealtime->relayPending(50);
            $row2 = $this->db->createCommand(
                'SELECT dispatched_at, attempts, dead_lettered_at FROM realtime.outbox_events WHERE seq = :s',
                [':s' => $ev->seq],
            )->queryOne();
            $this->assertIsArray($row2);
            $this->assertNull($row2['dispatched_at']);
            $this->assertSame(2, (int) $row2['attempts']);
            $this->assertNotNull($row2['dead_lettered_at']);

            // Out-of-order commit simulation: seqB dispatched first, seqA remains undispatched -> healthy relayPending picks up seqA!
            $evA = $this->realtime->publish($orgId, 'org:' . $orgId, 'test.ooo', ['order' => 1]);
            $evB = $this->realtime->publish($orgId, 'org:' . $orgId, 'test.ooo', ['order' => 2]);
            $this->assertSame(1, $this->realtime->tryPushEvents([$evB]));
            $dispA = $this->db->createCommand('SELECT dispatched_at FROM realtime.outbox_events WHERE seq = :s', [':s' => $evA->seq])->queryScalar();
            $this->assertNull($dispA);

            $this->realtime->relayPending(100);
            $dispAAfter = $this->db->createCommand('SELECT dispatched_at FROM realtime.outbox_events WHERE seq = :s', [':s' => $evA->seq])->queryScalar();
            $this->assertNotNull($dispAAfter);
        } finally {
            $tx->rollBack();
        }
    }

    public function testGate07ChatSendMessageAndListRoomsLateralAndBatchPresence(): void
    {
        $orgId = $this->createOrg('gate07');
        $teacher = $this->createMember($orgId, 'Perf Teacher', 'education.teacher');
        $student = $this->createMember($orgId, 'Perf Student', 'education.student');

        $this->db->createCommand(
            'INSERT INTO education.teachers (organization_id, account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))',
            [':o' => $orgId, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
        )->execute();
        $sid = (string) $this->db->createCommand(
            'INSERT INTO education.students (organization_id, owner_account_id, person_id) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text',
            [':o' => $orgId, ':a' => $teacher['accountId'], ':p' => $student['personId']],
        )->queryScalar();
        $gid = (string) $this->db->createCommand(
            "INSERT INTO education.groups (organization_id, owner_account_id, owner_person_id, name) VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), 'Perf Group') RETURNING id::text",
            [':o' => $orgId, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
        )->queryScalar();
        $this->db->createCommand(
            'INSERT INTO education.group_students (group_id, student_id, organization_id, owner_account_id) VALUES (CAST(:g AS uuid), CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid))',
            [':g' => $gid, ':s' => $sid, ':o' => $orgId, ':a' => $teacher['accountId']],
        )->execute();

        $room = $this->chat->createRoom($teacher['identity'], $orgId, [
            'roomType' => 'direct',
            'title' => 'Perf Chat',
            'memberPersonIds' => [$student['personId']],
        ]);
        $roomId = (string) $room['id'];

        $sent = $this->chat->sendMessage($teacher['identity'], $orgId, $roomId, ['body' => 'Fast message 1']);
        $this->assertSame('Fast message 1', $sent['body']);

        // Verify outbox events created by sendMessage were immediately pushed without full-table relayPending
        $undispatched = (int) $this->db->createCommand(
            'SELECT count(*) FROM realtime.outbox_events WHERE organization_id = CAST(:o AS uuid) AND dispatched_at IS NULL',
            [':o' => $orgId],
        )->queryScalar();
        $this->assertSame(0, $undispatched);

        // Verify listRooms LATERAL query returns exact peer, unreadCount, and lastMessage fields
        $studentRooms = $this->chat->listRooms($student['identity'], $orgId, ['tab' => 'unread']);
        $this->assertCount(1, $studentRooms['items']);
        $item = $studentRooms['items'][0];
        $this->assertSame($roomId, $item['id']);
        $this->assertSame(2, $item['memberCount']);
        $this->assertSame($teacher['personId'], $item['peerPersonId']);
        $this->assertSame('Fast message 1', $item['lastMessageBody']);
        $this->assertSame(1, $item['unreadCount']);

        // Verify batch mget presence
        $this->realtime->setPresence($orgId, $teacher['personId'], true);
        $presences = $this->realtime->getPresences($orgId, [$teacher['personId'], $student['personId']]);
        $this->assertCount(2, $presences);
        $this->assertSame('online', $presences[0]['status']);
        $this->assertSame('offline', $presences[1]['status']);
    }
}
