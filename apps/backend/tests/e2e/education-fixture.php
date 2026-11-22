<?php

declare(strict_types=1);

// Disposable identities, roster and test clocks. Education business actions are exercised via HTTP/UI.
require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Auth\Application\PasswordHasherInterface;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Education\Application\EducationStore;
use App\Environment;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
$db = $container->get(ConnectionInterface::class);
TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
$proof = getenv('E2E_ISOLATION_PROOF');
if (getenv('EDUCATION_ENABLED') !== '1' || !is_string($proof) || strlen($proof) < 32) {
    throw new RuntimeException('Proven disposable Education runtime required.');
}
$command = $argv[1] ?? '';
if (in_array($command, ['enable', 'disable'], true)) {
    $container->get(\App\Core\Module\Application\ModuleRepository::class)->setEnabled('education', $command === 'enable');
    echo json_encode(['updated' => true], JSON_THROW_ON_ERROR);
    exit;
}
if (!in_array($command, ['seed', 'seed-roles', 'expire-parent-invite', 'set-storage-quota'], true)) {
    throw new RuntimeException('Unsupported Education test command.');
}
$legacySeed = $command === 'seed';
if ($legacySeed) {
    $container->get(\Predis\ClientInterface::class)->flushdb();
} elseif (!hash_equals(hash('sha256', $proof), $argv[2] ?? '')) {
    throw new RuntimeException('Education role fixture must use the proven Core container.');
}
$role = $argv[3] ?? '';
if ($command === 'set-storage-quota') {
    $student = $argv[4] ?? '';
    $bytes = filter_var($argv[5] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($bytes === false) {
        throw new RuntimeException('Invalid disposable storage quota.');
    }
    $updated = $db->createCommand(
        "UPDATE storage.spaces s SET max_bytes=:bytes
        FROM core.organizations o WHERE o.id=s.organization_id AND o.code LIKE 'education.browser.%'
        AND s.organization_id=CAST(:org AS uuid) AND s.student_id=CAST(:student AS uuid)",
        [':org' => $role, ':student' => $student, ':bytes' => $bytes],
    )->execute();
    if ($updated !== 1) {
        throw new RuntimeException('Expected one browser fixture storage space.');
    }
    echo json_encode(['updated' => true], JSON_THROW_ON_ERROR);
    exit;
}
if ($command === 'expire-parent-invite') {
    $updated = $db->createCommand(
        "UPDATE education.parent_invites i
        SET created_at=now()-interval '2 hours',expires_at=now()-interval '1 hour'
        FROM core.organizations o
        WHERE i.id=CAST(:invite AS uuid) AND i.organization_id=o.id
        AND o.code LIKE 'education.browser.%' AND i.used_at IS NULL AND i.revoked_at IS NULL",
        [':invite' => $role],
    )->execute();
    if ($updated !== 1) {
        throw new RuntimeException('Expected one active browser fixture invitation.');
    }
    echo json_encode(['updated' => true], JSON_THROW_ON_ERROR);
    exit;
}
if (!$legacySeed && !in_array($role, ['student', 'parent', 'device', 'teacher', 'storage', 'classroom'], true)) {
    throw new RuntimeException('Unsupported Education test role.');
}
$result = $db->transaction(function () use ($db, $container, $command, $role): array {
    if ($command === 'seed') {
        $db->createCommand("UPDATE core.modules SET enabled=true,enabled_at=now(),disabled_at=NULL WHERE code='education'")->execute();
    }
    $testModules = match ($role) {
        'student', 'storage' => ['storage'],
        'device' => ['device_management'],
        'classroom' => ['device_management', 'storage'],
        default => [],
    };
    foreach ($testModules as $testModule) {
        $container->get(\App\Core\Module\Application\ModuleRepository::class)->setEnabled($testModule, true);
    }
    $organization = (string) $db->createCommand(
        "INSERT INTO core.organizations(code,name) VALUES(:code,'Education browser test') RETURNING id::text",
        [':code' => 'education.browser.' . bin2hex(random_bytes(6))],
    )->queryScalar();
    $otherOrganization = (string) $db->createCommand(
        "INSERT INTO core.organizations(code,name) VALUES(:code,'Other organization') RETURNING id::text",
        [':code' => 'education.other.' . bin2hex(random_bytes(6))],
    )->queryScalar();
    $accounts = [];
    $kinds = $command === 'seed-roles'
        ? ['teacher', 'student', 'parent']
        : ['teacher', 'colleague', 'outsider', 'memberOnly'];
    foreach ($kinds as $kind) {
        $name = match ($kind) {
            'teacher' => 'Юрий', 'colleague' => 'Анна', 'outsider' => 'Другой преподаватель', 'student' => 'Мира', 'parent' => 'Александра', default => 'Участник без назначения',
        };
        $person = (string) $db->createCommand("INSERT INTO core.persons(given_name,family_name,display_name) VALUES(:name,'Browser',:name) RETURNING id::text", [':name' => $name])->queryScalar();
        $account = (string) $db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label,locale,timezone)
            VALUES(CAST(:person AS uuid),'human','active',:name,'ru-RU','Asia/Yekaterinburg') RETURNING id::text", [':person' => $person, ':name' => $name])->queryScalar();
        $email = 'education-' . strtolower($kind) . '-' . bin2hex(random_bytes(6)) . '@example.test';
        $password = bin2hex(random_bytes(18));
        $db->createCommand("INSERT INTO core.auth_identifiers(account_id,identifier_type,value,normalized_value,is_primary,can_login,can_recover,verified_at)
            VALUES(CAST(:account AS uuid),'email',:email,:email,true,true,true,now())", [':account' => $account, ':email' => $email])->execute();
        $db->createCommand("INSERT INTO core.auth_password_credentials(account_id,password_hash,password_algorithm,must_change_password)
            VALUES(CAST(:account AS uuid),:hash,'argon2id',false)", [':account' => $account, ':hash' => $container->get(PasswordHasherInterface::class)->hash($password)])->execute();
        $org = $kind === 'outsider' ? $otherOrganization : $organization;
        $db->createCommand('INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))', [':org' => $org, ':person' => $person])->execute();
        if ($kind === 'student') {
            $db->createCommand("INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:account AS uuid),id FROM core.roles WHERE code='education.student'", [':account' => $account])->execute();
        }
        if (!in_array($kind, ['student', 'parent'], true)) {
            $db->createCommand("INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:account AS uuid),id FROM core.roles WHERE code='education.teacher'", [':account' => $account])->execute();
            if ($kind !== 'memberOnly') {
                $container->get(EducationStore::class)->grantTeacher($org, $account, $person);
            }
        }
        if ($command === 'seed-roles' && $kind === 'teacher') {
            $db->createCommand("INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:account AS uuid),id FROM core.roles WHERE code='education.family_verifier'", [':account' => $account])->execute();
        }
        $accounts[$kind] = ['id' => $account, 'personId' => $person, 'email' => $email, 'password' => $password, 'organizationId' => $org];
    }
    $switchOrganization = null;
    if ($role === 'storage') {
        $switchOrganization = (string) $db->createCommand(
            "INSERT INTO core.organizations(code,name) VALUES(:code,'Education switch test') RETURNING id::text",
            [':code' => 'education.browser.' . bin2hex(random_bytes(6))],
        )->queryScalar();
        $studentAccount = $accounts['student'];
        $teacherAccount = $accounts['teacher'];
        $db->createCommand(
            'INSERT INTO core.organization_memberships(organization_id,person_id)
            VALUES(CAST(:org AS uuid),CAST(:student AS uuid))',
            [':org' => $switchOrganization, ':student' => $studentAccount['personId']],
        )->execute();
        $secondStudent = $db->createCommand(
            'INSERT INTO education.students(organization_id,owner_account_id,person_id)
            VALUES(CAST(:org AS uuid),CAST(:teacher AS uuid),CAST(:person AS uuid)) RETURNING id::text',
            [':org' => $switchOrganization, ':teacher' => $teacherAccount['id'], ':person' => $studentAccount['personId']],
        )->queryScalar();
        $db->createCommand(
            'INSERT INTO education.student_accounts(student_id,organization_id,account_id,person_id,activated_at)
            VALUES(CAST(:student AS uuid),CAST(:org AS uuid),CAST(:account AS uuid),CAST(:person AS uuid),now())',
            [':student' => $secondStudent, ':org' => $switchOrganization, ':account' => $studentAccount['id'], ':person' => $studentAccount['personId']],
        )->execute();
    }
    $candidates = [];
    for ($index = 0; $index < 12; $index++) {
        $name = $index === 0 ? 'Илья Гилёв' : 'Ученик ' . ($index + 1);
        $person = (string) $db->createCommand("INSERT INTO core.persons(given_name,family_name,display_name) VALUES(:name,'Browser',:name) RETURNING id::text", [':name' => $name])->queryScalar();
        $db->createCommand('INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))', [':org' => $organization, ':person' => $person])->execute();
        $candidates[] = ['id' => $person, 'name' => $name];
    }
    return ['organizationId' => $organization, 'otherOrganizationId' => $otherOrganization,
        'switchOrganizationId' => $switchOrganization, 'accounts' => $accounts, 'candidates' => $candidates];
});
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
