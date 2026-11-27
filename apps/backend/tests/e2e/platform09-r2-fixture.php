<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth\Application\PasswordHasherInterface;
use App\Core\Auth\Application\SessionIssuer;
use App\Core\Client\Domain\ClientContext;
use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Domain\DeviceInfo;
use App\Core\Geo\Domain\GeoLocation;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Domain\Locale;
use App\Core\Time\Application\ResolvedTimeZone;
use App\Core\Time\Domain\TimeZone;
use App\Environment;
use App\Storage\Application\StorageFileAdapter;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$runner = new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv());
$container = $runner->getContainer();
$db = $container->get(ConnectionInterface::class);
$issuer = $container->get(SessionIssuer::class);
$hasher = $container->get(PasswordHasherInterface::class);

$command = $argv[1] ?? '';

function testClientContext(): ClientContext
{
    return new ClientContext(
        ipAddress: '127.0.0.1',
        userAgent: 'Platform09 R2 Fixture',
        acceptLanguage: 'ru-RU',
        locale: new LocaleContext(new Locale('ru-RU'), 'test'),
        reportedTimezone: 'UTC',
        timezone: new ResolvedTimeZone(new TimeZone('UTC'), ResolvedTimeZone::DEFAULT),
        clientHints: new ClientHints(null, 'Test', false),
        device: new DeviceInfo('Test', '1', 'Test', '1', 'desktop', null, 'test', 'Platform09 R2 Fixture'),
        clientInstanceId: null,
        fingerprintHashHex: null,
        geoLocation: new GeoLocation(null, null, null, null, null),
        requestId: '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
    );
}

/**
 * @return array{personId: string, accountId: string, email: string, password: string, token: string}
 */
function seedActor(
    ConnectionInterface $db,
    SessionIssuer $issuer,
    ClientContext $ctx,
    string $orgId,
    string $givenName,
    string $displayName,
    string $email,
    string $pwdHash,
    string $password,
    string $roleCode,
): array {
    $personId = (string) $db->createCommand(
        "INSERT INTO core.persons(given_name, family_name, display_name) VALUES(:g, 'R2Test', :d) RETURNING id::text",
        [':g' => $givenName, ':d' => $displayName],
    )->queryScalar();
    $accountId = (string) $db->createCommand(
        "INSERT INTO core.accounts(person_id, account_type, status, label) VALUES(CAST(:p AS uuid), 'human', 'active', :d) RETURNING id::text",
        [':p' => $personId, ':d' => $displayName],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO core.auth_identifiers(account_id, identifier_type, value, normalized_value, is_primary, can_login, can_recover, verified_at)
         VALUES(CAST(:a AS uuid), 'email', :e, :e, true, true, true, now())",
        [':a' => $accountId, ':e' => $email],
    )->execute();
    $db->createCommand(
        "INSERT INTO core.auth_password_credentials(account_id, password_hash, password_algorithm, must_change_password)
         VALUES(CAST(:a AS uuid), :h, 'argon2id', false)",
        [':a' => $accountId, ':h' => $pwdHash],
    )->execute();
    $db->createCommand(
        "INSERT INTO core.organization_memberships(organization_id, person_id) VALUES(CAST(:o AS uuid), CAST(:p AS uuid))",
        [':o' => $orgId, ':p' => $personId],
    )->execute();
    $db->createCommand(
        "INSERT INTO core.account_roles(account_id, role_id) SELECT CAST(:a AS uuid), id FROM core.roles WHERE code = :r",
        [':a' => $accountId, ':r' => $roleCode],
    )->execute();

    $token = $issuer->issue($accountId, 'password', $ctx, false)->accessToken;

    return [
        'personId' => $personId,
        'accountId' => $accountId,
        'email' => $email,
        'password' => $password,
        'token' => $token,
    ];
}

if ($command === 'seed') {
    $nonce = bin2hex(random_bytes(6));
    $password = 'Platform09SecurePass!';
    $pwdHash = $hasher->hash($password);
    $ctx = testClientContext();

    $orgA = (string) $db->createCommand(
        "INSERT INTO core.organizations(code, name) VALUES(:code, 'R2 E2E Organization') RETURNING id::text",
        [':code' => 'education.r2.' . $nonce],
    )->queryScalar();

    $orgB = (string) $db->createCommand(
        "INSERT INTO core.organizations(code, name) VALUES(:code, 'R2 Outsider Organization') RETURNING id::text",
        [':code' => 'education.outsider.' . $nonce],
    )->queryScalar();

    // Primary Teacher in Org A (owner of groupId)
    $teacher = seedActor($db, $issuer, $ctx, $orgA, 'R2Teacher', 'R2 Teacher', 'r2-teacher-' . $nonce . '@example.test', $pwdHash, $password, 'education.teacher');
    $db->createCommand(
        "INSERT INTO education.teachers(organization_id, account_id, person_id) VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))",
        [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
    )->execute();

    // Unrelated Teacher 2 in Org A (NOT assigned to groupId)
    $teacher2 = seedActor($db, $issuer, $ctx, $orgA, 'R2TeacherTwo', 'R2 Unrelated Teacher', 'r2-teacher2-' . $nonce . '@example.test', $pwdHash, $password, 'education.teacher');
    $db->createCommand(
        "INSERT INTO education.teachers(organization_id, account_id, person_id) VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))",
        [':o' => $orgA, ':a' => $teacher2['accountId'], ':p' => $teacher2['personId']],
    )->execute();

    // Primary Student in Org A
    $student = seedActor($db, $issuer, $ctx, $orgA, 'R2Student', 'R2 Student', 'r2-student-' . $nonce . '@example.test', $pwdHash, $password, 'education.student');
    $studentId = (string) $db->createCommand(
        "INSERT INTO education.students(organization_id, owner_account_id, person_id)
         VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text",
        [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $student['personId']],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO education.student_accounts(student_id, organization_id, account_id, person_id, activated_at)
         VALUES(CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), now())",
        [':s' => $studentId, ':o' => $orgA, ':a' => $student['accountId'], ':p' => $student['personId']],
    )->execute();
    $student['studentId'] = $studentId;

    // Confirmed Guardian of Student 1 (Family 1 Parent)
    $parent1 = seedActor($db, $issuer, $ctx, $orgA, 'R2ParentOne', 'R2 Parent One', 'r2-parent1-' . $nonce . '@example.test', $pwdHash, $password, 'education.parent');
    $db->createCommand(
        "INSERT INTO education.student_guardians(organization_id, student_id, guardian_person_id, relationship, status, confirmed_at, confirmed_by_account_id)
         VALUES(CAST(:o AS uuid), CAST(:s AS uuid), CAST(:p AS uuid), 'mother', 'confirmed', now(), CAST(:c AS uuid))",
        [':o' => $orgA, ':s' => $studentId, ':p' => $parent1['personId'], ':c' => $teacher['accountId']],
    )->execute();
    $db->createCommand(
        "INSERT INTO education.parent_accounts(organization_id, guardian_person_id, account_id, activated_at)
         VALUES(CAST(:o AS uuid), CAST(:p AS uuid), CAST(:a AS uuid), now())",
        [':o' => $orgA, ':p' => $parent1['personId'], ':a' => $parent1['accountId']],
    )->execute();

    // Unrelated Student 2 + Confirmed Parent 2 (Family 2 in same Org A)
    $student2 = seedActor($db, $issuer, $ctx, $orgA, 'R2StudentTwo', 'R2 Unrelated Student', 'r2-student2-' . $nonce . '@example.test', $pwdHash, $password, 'education.student');
    $student2Id = (string) $db->createCommand(
        "INSERT INTO education.students(organization_id, owner_account_id, person_id)
         VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text",
        [':o' => $orgA, ':a' => $teacher2['accountId'], ':p' => $student2['personId']],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO education.student_accounts(student_id, organization_id, account_id, person_id, activated_at)
         VALUES(CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), now())",
        [':s' => $student2Id, ':o' => $orgA, ':a' => $student2['accountId'], ':p' => $student2['personId']],
    )->execute();
    $student2['studentId'] = $student2Id;

    $parent2 = seedActor($db, $issuer, $ctx, $orgA, 'R2ParentTwo', 'R2 Family Two Parent', 'r2-parent2-' . $nonce . '@example.test', $pwdHash, $password, 'education.parent');
    $db->createCommand(
        "INSERT INTO education.student_guardians(organization_id, student_id, guardian_person_id, relationship, status, confirmed_at, confirmed_by_account_id)
         VALUES(CAST(:o AS uuid), CAST(:s AS uuid), CAST(:p AS uuid), 'father', 'confirmed', now(), CAST(:c AS uuid))",
        [':o' => $orgA, ':s' => $student2Id, ':p' => $parent2['personId'], ':c' => $teacher2['accountId']],
    )->execute();
    $db->createCommand(
        "INSERT INTO education.parent_accounts(organization_id, guardian_person_id, account_id, activated_at)
         VALUES(CAST(:o AS uuid), CAST(:p AS uuid), CAST(:a AS uuid), now())",
        [':o' => $orgA, ':p' => $parent2['personId'], ':a' => $parent2['accountId']],
    )->execute();

    // Group for Teacher + Student 1
    $groupId = (string) $db->createCommand(
        "INSERT INTO education.groups(organization_id, owner_account_id, owner_person_id, name)
         VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), 'R2 Test Group') RETURNING id::text",
        [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO education.group_teachers(group_id, organization_id, account_id, assigned_by_account_id)
         VALUES(CAST(:g AS uuid), CAST(:o AS uuid), CAST(:a AS uuid), CAST(:a AS uuid))",
        [':g' => $groupId, ':o' => $orgA, ':a' => $teacher['accountId']],
    )->execute();
    $db->createCommand(
        "INSERT INTO education.group_students(group_id, student_id, organization_id, owner_account_id)
         VALUES(CAST(:g AS uuid), CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid))",
        [':g' => $groupId, ':s' => $studentId, ':o' => $orgA, ':a' => $teacher['accountId']],
    )->execute();

    // Direct chat thread between Teacher and Student 1
    $threadId = (string) $db->createCommand(
        "INSERT INTO education.threads(organization_id, subject)
         VALUES(CAST(:o AS uuid), 'R2 Chat') RETURNING id::text",
        [':o' => $orgA],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO education.thread_members(thread_id, organization_id, person_id)
         VALUES(CAST(:t AS uuid), CAST(:o AS uuid), CAST(:p AS uuid))",
        [':t' => $threadId, ':o' => $orgA, ':p' => $teacher['personId']],
    )->execute();
    $db->createCommand(
        "INSERT INTO education.thread_members(thread_id, organization_id, person_id)
         VALUES(CAST(:t AS uuid), CAST(:o AS uuid), CAST(:p AS uuid))",
        [':t' => $threadId, ':o' => $orgA, ':p' => $student['personId']],
    )->execute();
    $db->createCommand(
        "INSERT INTO chat.rooms(id, organization_id, room_type, title, last_activity_at)
         VALUES(CAST(:id AS uuid), CAST(:o AS uuid), 'direct', 'R2 Chat', now())
         ON CONFLICT (id) DO NOTHING",
        [':id' => $threadId, ':o' => $orgA],
    )->execute();
    foreach ([$teacher['personId'], $student['personId']] as $memberPid) {
        $db->createCommand(
            "INSERT INTO chat.members(room_id, organization_id, person_id, role, can_write)
             VALUES(CAST(:id AS uuid), CAST(:o AS uuid), CAST(:p AS uuid), 'member', true)
             ON CONFLICT (room_id, person_id) DO NOTHING",
            [':id' => $threadId, ':o' => $orgA, ':p' => $memberPid],
        )->execute();
    }

    // Outsider in Org B
    $outsider = seedActor($db, $issuer, $ctx, $orgB, 'R2Outsider', 'R2 Outsider', 'r2-outsider-' . $nonce . '@example.test', $pwdHash, $password, 'education.student');

    echo json_encode([
        'nonce' => $nonce,
        'organizationId' => $orgA,
        'otherOrganizationId' => $orgB,
        'groupId' => $groupId,
        'threadId' => $threadId,
        'teacher' => $teacher,
        'teacher2' => $teacher2,
        'student' => $student,
        'parent1' => $parent1,
        'student2' => $student2,
        'parent2' => $parent2,
        'outsider' => $outsider,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($command === 'expire-attempt') {
    $attemptId = $argv[2] ?? '';
    if ($attemptId !== '') {
        $db->createCommand(
            "UPDATE education.assessment_attempts SET started_at = now() - interval '2 hours' WHERE id = CAST(:id AS uuid)",
            [':id' => $attemptId],
        )->execute();
        echo json_encode(['status' => 'expired', 'attemptId' => $attemptId]);
    }
    exit(0);
}

if ($command === 'verify-s3') {
    $storageKey = $argv[2] ?? '';
    $storage = $container->get(StorageFileAdapter::class);
    $exists = $storage->exists($storageKey);
    if (!$exists) {
        echo json_encode(['exists' => false, 'storageKey' => $storageKey]);
        exit(0);
    }
    $stream = $storage->open($storageKey);
    $ctx = hash_init('sha256');
    $bytes = 0;
    while (!feof($stream)) {
        $chunk = fread($stream, 65536);
        if ($chunk === false || $chunk === '') {
            continue;
        }
        $bytes += strlen($chunk);
        hash_update($ctx, $chunk);
    }
    $sha256 = hash_final($ctx);
    fclose($stream);
    echo json_encode([
        'exists' => true,
        'storageKey' => $storageKey,
        'sizeBytes' => $bytes,
        'sha256' => $sha256,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($command === 'verify-db') {
    $payload = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
    $result = [];
    if (!empty($payload['assessmentAttemptId'])) {
        $result['assessmentAttempt'] = $db->createCommand(
            'SELECT id::text, state, score, max_score AS "maxScore", percentage, passed, revision
             FROM education.assessment_attempts WHERE id = CAST(:id AS uuid)',
            [':id' => (string) $payload['assessmentAttemptId']],
        )->queryOne();
    }
    if (!empty($payload['homeworkAssignmentId'])) {
        $result['homeworkSubmission'] = $db->createCommand(
            'SELECT hs.id::text, hs.state, hs.version, hs.selected_activity_attempt_id::text AS "selectedActivityAttemptId"
             FROM education.homework_submissions hs
             JOIN education.homework_recipients hr ON hr.id = hs.recipient_id
             WHERE hr.assignment_id = CAST(:id AS uuid)',
            [':id' => (string) $payload['homeworkAssignmentId']],
        )->queryOne();
        $result['homeworkAttemptLink'] = $db->createCommand(
            'SELECT hal.attempt_id::text AS "attemptId", hal.recipient_id::text AS "recipientId", hal.answer_sequence AS "answerSequence"
             FROM education.homework_attempt_links hal
             JOIN education.homework_recipients hr ON hr.id = hal.recipient_id
             WHERE hr.assignment_id = CAST(:id AS uuid)
             ORDER BY hal.answer_sequence DESC LIMIT 1',
            [':id' => (string) $payload['homeworkAssignmentId']],
        )->queryOne();
    }
    if (!empty($payload['voiceAssetId'])) {
        $result['voiceAsset'] = $db->createCommand(
            'SELECT id::text, status, size_bytes AS "sizeBytes", sha256, verified_mime AS "verifiedMime", duration_seconds AS "durationSeconds", storage_key::text AS "storageKey"
             FROM media.assets WHERE id = CAST(:id AS uuid)',
            [':id' => (string) $payload['voiceAssetId']],
        )->queryOne();
    }
    if (!empty($payload['videoAssetId'])) {
        $result['videoAsset'] = $db->createCommand(
            'SELECT id::text, status, size_bytes AS "sizeBytes", sha256, verified_mime AS "verifiedMime", duration_seconds AS "durationSeconds", storage_key::text AS "storageKey"
             FROM media.assets WHERE id = CAST(:id AS uuid)',
            [':id' => (string) $payload['videoAssetId']],
        )->queryOne();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit(0);
}

if ($command === 'cleanup') {
    $nonce = $argv[2] ?? '';
    if ($nonce !== '') {
        $orgs = $db->createCommand(
            "SELECT id::text FROM core.organizations WHERE code IN (:cA, :cB)",
            [':cA' => 'education.r2.' . $nonce, ':cB' => 'education.outsider.' . $nonce],
        )->queryColumn();

        $db->createCommand("SELECT set_config('education.family_erasure', 'on', false)")->execute();
        $db->createCommand("SELECT set_config('education.homework_erasure', 'on', false)")->execute();
        foreach ($orgs as $orgId) {
            $db->createCommand("DELETE FROM education.notifications WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.messages WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.thread_members WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.threads WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.homework_attempt_links WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.homework_submissions WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.activity_attempts WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.homework_recipients WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.activity_assignments WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("ALTER TABLE education.homework_revisions DISABLE TRIGGER homework_revision_immutable")->execute();
            $db->createCommand("DELETE FROM education.homework_revisions WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("ALTER TABLE education.homework_revisions ENABLE TRIGGER homework_revision_immutable")->execute();
            $db->createCommand("DELETE FROM education.homework_assignments WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.assessment_attempts WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("UPDATE education.assessments SET latest_version_id = NULL WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.assessment_versions WHERE assessment_id IN (SELECT id FROM education.assessments WHERE organization_id = CAST(:o AS uuid))", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.assessments WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM media.assets WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.family_history WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.parent_invites WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.student_guardians WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.parent_accounts WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.group_students WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.group_teachers WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.groups WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.student_accounts WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.students WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.teachers WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM activity.attempts WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM activity.assignments WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("UPDATE activity.items SET active_version_id = NULL WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM activity.items WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM activity.question_bank WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM chat.rooms WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM notifications.items WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM realtime.outbox_events WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM realtime.connections WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM core.organization_memberships WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM core.organizations WHERE id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
        }
        echo json_encode(['cleaned' => true, 'nonce' => $nonce]);
    }
    exit(0);
}

echo json_encode(['error' => 'Unknown command']);
