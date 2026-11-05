<?php

declare(strict_types=1);

// Disposable test identities and Core roster only. Education business records are created through the real API/UI.
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
if ($command !== 'seed') {
    throw new RuntimeException('Unsupported Education test command.');
}
$container->get(\Predis\ClientInterface::class)->flushdb();
$result = $db->transaction(function () use ($db, $container): array {
    $db->createCommand("UPDATE core.modules SET enabled=true,enabled_at=now(),disabled_at=NULL WHERE code='education'")->execute();
    $organization = (string) $db->createCommand(
        "INSERT INTO core.organizations(code,name) VALUES(:code,'Education browser test') RETURNING id::text",
        [':code' => 'education.browser.' . bin2hex(random_bytes(6))],
    )->queryScalar();
    $otherOrganization = (string) $db->createCommand(
        "INSERT INTO core.organizations(code,name) VALUES(:code,'Other organization') RETURNING id::text",
        [':code' => 'education.other.' . bin2hex(random_bytes(6))],
    )->queryScalar();
    $accounts = [];
    foreach (['teacher', 'colleague', 'outsider', 'memberOnly'] as $kind) {
        $name = match ($kind) {
            'teacher' => 'Юрий', 'colleague' => 'Анна', 'outsider' => 'Другой преподаватель', default => 'Участник без назначения',
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
        $db->createCommand("INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:account AS uuid),id FROM core.roles WHERE code='education.teacher'", [':account' => $account])->execute();
        if ($kind !== 'memberOnly') {
            $container->get(EducationStore::class)->grantTeacher($org, $account, $person);
        }
        $accounts[$kind] = ['id' => $account, 'personId' => $person, 'email' => $email, 'password' => $password, 'organizationId' => $org];
    }
    $candidates = [];
    for ($index = 0; $index < 12; $index++) {
        $name = $index === 0 ? 'Илья Гилёв' : 'Ученик ' . ($index + 1);
        $person = (string) $db->createCommand("INSERT INTO core.persons(given_name,family_name,display_name) VALUES(:name,'Browser',:name) RETURNING id::text", [':name' => $name])->queryScalar();
        $db->createCommand('INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))', [':org' => $organization, ':person' => $person])->execute();
        $candidates[] = ['id' => $person, 'name' => $name];
    }
    return ['organizationId' => $organization, 'otherOrganizationId' => $otherOrganization, 'accounts' => $accounts, 'candidates' => $candidates];
});
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
