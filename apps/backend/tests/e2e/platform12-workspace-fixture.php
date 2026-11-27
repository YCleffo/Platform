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

function p12ClientContext(): ClientContext
{
    return new ClientContext(
        ipAddress: '127.0.0.1',
        userAgent: 'Platform12 Workspace Fixture',
        acceptLanguage: 'en-US',
        locale: new LocaleContext(new Locale('en-US'), 'test'),
        reportedTimezone: 'UTC',
        timezone: new ResolvedTimeZone(new TimeZone('UTC'), ResolvedTimeZone::DEFAULT),
        clientHints: new ClientHints(null, 'Test', false),
        device: new DeviceInfo('Test', '1', 'Test', '1', 'desktop', null, 'test', 'Platform12 Workspace Fixture'),
        clientInstanceId: null,
        fingerprintHashHex: null,
        geoLocation: new GeoLocation(null, null, null, null, null),
        requestId: '018f2892-bbbb-7bbb-8bbb-ffffffffffff',
    );
}

/**
 * @param list<string> $orgIds
 * @return array{personId: string, accountId: string, email: string, password: string, token: string}
 */
function p12SeedActor(
    ConnectionInterface $db,
    SessionIssuer $issuer,
    ClientContext $ctx,
    array $orgIds,
    string $givenName,
    string $displayName,
    string $email,
    string $pwdHash,
    string $password,
    string $roleCode,
): array {
    $personId = (string) $db->createCommand(
        "INSERT INTO core.persons(given_name, family_name, display_name) VALUES(:g, 'P12Test', :d) RETURNING id::text",
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
    foreach ($orgIds as $orgId) {
        $db->createCommand(
            "INSERT INTO core.organization_memberships(organization_id, person_id) VALUES(CAST(:o AS uuid), CAST(:p AS uuid))",
            [':o' => $orgId, ':p' => $personId],
        )->execute();
    }
    $db->createCommand(
        "INSERT INTO core.account_roles(account_id, role_id) SELECT CAST(:a AS uuid), id FROM core.roles WHERE code = :r ON CONFLICT DO NOTHING",
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
    $password = 'Platform12SecurePass!';
    $pwdHash = $hasher->hash($password);
    $ctx = p12ClientContext();

    $orgA = (string) $db->createCommand(
        "INSERT INTO core.organizations(code, name) VALUES(:code, :name) RETURNING id::text",
        [':code' => 'education.p12a.' . $nonce, ':name' => 'P12 Org Alpha ' . $nonce],
    )->queryScalar();

    $orgB = (string) $db->createCommand(
        "INSERT INTO core.organizations(code, name) VALUES(:code, :name) RETURNING id::text",
        [':code' => 'education.p12b.' . $nonce, ':name' => 'P12 Org Beta ' . $nonce],
    )->queryScalar();

    // Multi-org teacher (belongs to both Org A and Org B)
    $teacher = p12SeedActor(
        $db,
        $issuer,
        $ctx,
        [$orgA, $orgB],
        'P12Teacher',
        'P12 Teacher ' . $nonce,
        'p12-teacher-' . $nonce . '@example.test',
        $pwdHash,
        $password,
        'education.teacher',
    );
    $db->createCommand(
        "INSERT INTO education.teachers(organization_id, account_id, person_id) VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))",
        [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
    )->execute();
    $db->createCommand(
        "INSERT INTO education.teachers(organization_id, account_id, person_id) VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid))",
        [':o' => $orgB, ':a' => $teacher['accountId'], ':p' => $teacher['personId']],
    )->execute();

    // Student 1 in Org A
    $student1 = p12SeedActor(
        $db,
        $issuer,
        $ctx,
        [$orgA],
        'P12StudentOne',
        'Иван Ученик ' . $nonce,
        'p12-student1-' . $nonce . '@example.test',
        $pwdHash,
        $password,
        'education.student',
    );
    $s1Id = (string) $db->createCommand(
        "INSERT INTO education.students(organization_id, owner_account_id, person_id)
         VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text",
        [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $student1['personId']],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO education.student_accounts(student_id, organization_id, account_id, person_id, activated_at)
         VALUES(CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), now())",
        [':s' => $s1Id, ':o' => $orgA, ':a' => $student1['accountId'], ':p' => $student1['personId']],
    )->execute();
    $student1['studentId'] = $s1Id;

    // Unrelated Student 2 in Org A (must NOT leak to Student 1)
    $student2 = p12SeedActor(
        $db,
        $issuer,
        $ctx,
        [$orgA],
        'P12HiddenStudent',
        'Скрытый Студент ' . $nonce,
        'p12-student2-' . $nonce . '@example.test',
        $pwdHash,
        $password,
        'education.student',
    );
    $s2Id = (string) $db->createCommand(
        "INSERT INTO education.students(organization_id, owner_account_id, person_id)
         VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid)) RETURNING id::text",
        [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $student2['personId']],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO education.student_accounts(student_id, organization_id, account_id, person_id, activated_at)
         VALUES(CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), now())",
        [':s' => $s2Id, ':o' => $orgA, ':a' => $student2['accountId'], ':p' => $student2['personId']],
    )->execute();
    $student2['studentId'] = $s2Id;

    // Confirmed Parent 1 for Student 1
    $parent1 = p12SeedActor(
        $db,
        $issuer,
        $ctx,
        [$orgA],
        'P12ParentOne',
        'P12 Confirmed Parent ' . $nonce,
        'p12-parent1-' . $nonce . '@example.test',
        $pwdHash,
        $password,
        'education.parent',
    );
    $db->createCommand(
        "INSERT INTO education.student_guardians(organization_id, student_id, guardian_person_id, relationship, status, confirmed_at, confirmed_by_account_id)
         VALUES(CAST(:o AS uuid), CAST(:s AS uuid), CAST(:p AS uuid), 'mother', 'confirmed', now(), CAST(:c AS uuid))",
        [':o' => $orgA, ':s' => $s1Id, ':p' => $parent1['personId'], ':c' => $teacher['accountId']],
    )->execute();
    $db->createCommand(
        "INSERT INTO education.parent_accounts(organization_id, guardian_person_id, account_id, activated_at)
         VALUES(CAST(:o AS uuid), CAST(:p AS uuid), CAST(:a AS uuid), now())",
        [':o' => $orgA, ':p' => $parent1['personId'], ':a' => $parent1['accountId']],
    )->execute();

    // Unconfirmed Parent for Student 1 (pending status -> MUST get 403)
    $unconfirmedParent = p12SeedActor(
        $db,
        $issuer,
        $ctx,
        [$orgA],
        'P12ParentPending',
        'P12 Pending Parent ' . $nonce,
        'p12-parent-pending-' . $nonce . '@example.test',
        $pwdHash,
        $password,
        'education.parent',
    );
    $db->createCommand(
        "INSERT INTO education.student_guardians(organization_id, student_id, guardian_person_id, relationship, status)
         VALUES(CAST(:o AS uuid), CAST(:s AS uuid), CAST(:p AS uuid), 'father', 'pending')",
        [':o' => $orgA, ':s' => $s1Id, ':p' => $unconfirmedParent['personId']],
    )->execute();

    // Group in Org A (with Student 1 enrolled)
    $groupA = (string) $db->createCommand(
        "INSERT INTO education.groups(organization_id, owner_account_id, owner_person_id, name)
         VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), :name) RETURNING id::text",
        [':o' => $orgA, ':a' => $teacher['accountId'], ':p' => $teacher['personId'], ':name' => 'Алгебра Quantum ' . $nonce],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO education.group_teachers(group_id, organization_id, account_id, assigned_by_account_id)
         VALUES(CAST(:g AS uuid), CAST(:o AS uuid), CAST(:a AS uuid), CAST(:a AS uuid))",
        [':g' => $groupA, ':o' => $orgA, ':a' => $teacher['accountId']],
    )->execute();
    $db->createCommand(
        "INSERT INTO education.group_students(group_id, student_id, organization_id, owner_account_id)
         VALUES(CAST(:g AS uuid), CAST(:s AS uuid), CAST(:o AS uuid), CAST(:a AS uuid))",
        [':g' => $groupA, ':s' => $s1Id, ':o' => $orgA, ':a' => $teacher['accountId']],
    )->execute();

    // Group in Org B (for Org B scope verification)
    $groupB = (string) $db->createCommand(
        "INSERT INTO education.groups(organization_id, owner_account_id, owner_person_id, name)
         VALUES(CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), :name) RETURNING id::text",
        [':o' => $orgB, ':a' => $teacher['accountId'], ':p' => $teacher['personId'], ':name' => 'Beta Physics ' . $nonce],
    )->queryScalar();
    $db->createCommand(
        "INSERT INTO education.group_teachers(group_id, organization_id, account_id, assigned_by_account_id)
         VALUES(CAST(:g AS uuid), CAST(:o AS uuid), CAST(:a AS uuid), CAST(:a AS uuid))",
        [':g' => $groupB, ':o' => $orgB, ':a' => $teacher['accountId']],
    )->execute();

    // Device alert in Org B so Org B dashboard shows different counts than Org A
    $db->createCommand(
        "INSERT INTO device_management.devices (organization_id, owner_account_id, owner_person_id, name, number, status)
         VALUES (CAST(:o AS uuid), CAST(:a AS uuid), CAST(:p AS uuid), :name, '01', 'ERROR')",
        [':o' => $orgB, ':a' => $teacher['accountId'], ':p' => $teacher['personId'], ':name' => 'BetaDevice-' . $nonce],
    )->execute();

    // Outsider in Org B ONLY
    $outsider = p12SeedActor(
        $db,
        $issuer,
        $ctx,
        [$orgB],
        'P12Outsider',
        'P12 Outsider ' . $nonce,
        'p12-outsider-' . $nonce . '@example.test',
        $pwdHash,
        $password,
        'education.student',
    );

    echo json_encode([
        'nonce' => $nonce,
        'orgA' => $orgA,
        'orgB' => $orgB,
        'groupA' => $groupA,
        'groupB' => $groupB,
        'teacher' => $teacher,
        'student1' => $student1,
        'student2' => $student2,
        'parent1' => $parent1,
        'unconfirmedParent' => $unconfirmedParent,
        'outsider' => $outsider,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($command === 'verify-s3') {
    $storageKey = $argv[2] ?? '';
    $storage = $container->get(StorageFileAdapter::class);
    $exists = $storage->exists($storageKey);
    if (!$exists) {
        echo json_encode(['exists' => false, 'storageKey' => $storageKey], JSON_THROW_ON_ERROR);
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

if ($command === 'expire-undo') {
    $opId = $argv[2] ?? '';
    if ($opId !== '') {
        $db->createCommand(
            "UPDATE workspace.operations SET undo_deadline = now() - interval '2 seconds' WHERE id = CAST(:id AS uuid)",
            [':id' => $opId],
        )->execute();
        echo json_encode(['expired' => true, 'operationId' => $opId], JSON_THROW_ON_ERROR);
    }
    exit(0);
}

if ($command === 'expire-attempt') {
    $attemptId = $argv[2] ?? '';
    if ($attemptId !== '') {
        $db->createCommand(
            "UPDATE activity.attempts SET expires_at = now() - interval '2 minutes' WHERE id = CAST(:id AS uuid)",
            [':id' => $attemptId],
        )->execute();
        echo json_encode(['expired' => true, 'attemptId' => $attemptId], JSON_THROW_ON_ERROR);
    }
    exit(0);
}

if ($command === 'revoke-parent') {
    $orgId = $argv[2] ?? '';
    $studentId = $argv[3] ?? '';
    $guardianPersonId = $argv[4] ?? '';
    $db->createCommand("SELECT set_config('education.family_erasure', 'on', false)")->execute();
    $db->createCommand(
        "UPDATE education.student_guardians
         SET status = 'revoked', revoked_at = now(), revoked_by_account_id = confirmed_by_account_id
         WHERE organization_id = CAST(:o AS uuid)
           AND student_id = CAST(:s AS uuid)
           AND guardian_person_id = CAST(:p AS uuid)",
        [':o' => $orgId, ':s' => $studentId, ':p' => $guardianPersonId],
    )->execute();
    echo json_encode(['revoked' => true], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($command === 'cleanup') {
    $nonce = $argv[2] ?? '';
    if ($nonce !== '') {
        $orgs = $db->createCommand(
            "SELECT id::text FROM core.organizations WHERE code IN (:cA, :cB)",
            [':cA' => 'education.p12a.' . $nonce, ':cB' => 'education.p12b.' . $nonce],
        )->queryColumn();

        $db->createCommand("SELECT set_config('education.family_erasure', 'on', false)")->execute();
        $db->createCommand("SELECT set_config('education.homework_erasure', 'on', false)")->execute();

        $storage = $container->get(StorageFileAdapter::class);
        foreach ($orgs as $orgId) {
            $keys = array_merge(
                $db->createCommand('SELECT storage_key::text FROM media.assets WHERE organization_id = CAST(:o AS uuid)', [':o' => $orgId])->queryColumn(),
                $db->createCommand('SELECT v.storage_key::text FROM storage.versions v JOIN storage.files f ON f.id = v.file_id JOIN storage.spaces s ON s.id = f.space_id WHERE s.organization_id = CAST(:o AS uuid)', [':o' => $orgId])->queryColumn(),
            );
            foreach ($keys as $k) {
                if (is_string($k) && $k !== '') {
                    try {
                        if ($storage->exists($k)) {
                            $storage->delete($k);
                        }
                    } catch (\Throwable) {
                    }
                }
            }

            $db->createCommand("DELETE FROM workspace.trash_items WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM workspace.operations WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM workspace.preferences WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM realtime.outbox_events WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM realtime.connections WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM notifications.push_subscriptions WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM notifications.items WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM chat.reactions WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM chat.messages WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM chat.members WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM chat.rooms WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM media.upload_sessions WHERE asset_id IN (SELECT id FROM media.assets WHERE organization_id = CAST(:o AS uuid))", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM media.variants WHERE asset_id IN (SELECT id FROM media.assets WHERE organization_id = CAST(:o AS uuid))", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM media.assets WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM storage.tickets WHERE space_id IN (SELECT id FROM storage.spaces WHERE organization_id = CAST(:o AS uuid))", [':o' => $orgId])->execute();
            $db->createCommand("UPDATE storage.files SET current_version_id = NULL WHERE space_id IN (SELECT id FROM storage.spaces WHERE organization_id = CAST(:o AS uuid))", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM storage.versions WHERE file_id IN (SELECT f.id FROM storage.files f JOIN storage.spaces s ON s.id = f.space_id WHERE s.organization_id = CAST(:o AS uuid))", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM storage.files WHERE space_id IN (SELECT id FROM storage.spaces WHERE organization_id = CAST(:o AS uuid))", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM storage.spaces WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM activity.attempts WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM activity.assignments WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("UPDATE activity.items SET active_version_id = NULL WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM activity.items WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM activity.question_bank WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM device_management.devices WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.notifications WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.messages WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.thread_members WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM education.threads WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
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
            $db->createCommand("DELETE FROM core.organization_memberships WHERE organization_id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
            $db->createCommand("DELETE FROM core.organizations WHERE id = CAST(:o AS uuid)", [':o' => $orgId])->execute();
        }
        echo json_encode(['cleaned' => true, 'nonce' => $nonce], JSON_THROW_ON_ERROR);
    }
    exit(0);
}

echo json_encode(['error' => 'Unknown command'], JSON_THROW_ON_ERROR);
