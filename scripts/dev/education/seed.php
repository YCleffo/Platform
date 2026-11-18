<?php

declare(strict_types=1);

require __DIR__ . '/guard.php';
educationFixtureDatabase();
require '/app/src/bootstrap.php';

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\PasswordHasherInterface;
use App\Core\Module\Application\ModuleRepository;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationService;
use App\Education\Application\EducationStore;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$container = (new HttpApplicationRunner(rootPath: '/app', environment: App\Environment::appEnv()))->getContainer();
$db = $container->get(ConnectionInterface::class);
App\Core\Shared\Infrastructure\TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
$result = $db->transaction(function () use ($db, $container): array {
    $db->createCommand('SELECT pg_advisory_xact_lock(1846202613)')->queryScalar();
    $db->createCommand('CREATE TABLE IF NOT EXISTS public.education_local_fixtures
        (name text PRIMARY KEY,value jsonb NOT NULL,created_at timestamptz NOT NULL DEFAULT now())')->execute();
    // Each fixture operation is checkpointed inside the same transaction as its
    // real application mutation. Reruns retain edits and create no duplicates.
    $remember = static function (string $key, callable $create) use ($db): array {
        $json = $db->createCommand('SELECT value::text FROM public.education_local_fixtures WHERE name=:key', [':key' => $key])->queryScalar();
        if (is_string($json)) return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $value = $create();
        $db->createCommand('INSERT INTO public.education_local_fixtures(name,value) VALUES(:key,CAST(:value AS jsonb))',
            [':key' => $key, ':value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)])->execute();
        return $value;
    };
    $organization = $remember('organization', static function () use ($db): array {
        return ['id' => (string) $db->createCommand("INSERT INTO core.organizations(code,name)
            VALUES('education.local.fixture','Учебная лаборатория · локальные данные') RETURNING id::text")->queryScalar()];
    });
    $otherOrganization = $remember('otherOrganization', static function () use ($db): array {
        return ['id' => (string) $db->createCommand("INSERT INTO core.organizations(code,name)
            VALUES('education.local.other','Другая организация · проверка доступа') RETURNING id::text")->queryScalar()];
    });
    $container->get(ModuleRepository::class)->setEnabled('education', true);
    $container->get(ModuleRepository::class)->setEnabled('storage', true);
    $accounts = [];
    foreach (['teacher' => 'Мария Александровна', 'colleague' => 'Алексей Сергеевич', 'outsider' => 'Преподаватель другой организации'] as $kind => $name) {
        $org = $kind === 'outsider' ? $otherOrganization['id'] : $organization['id'];
        $accounts[$kind] = $remember('account.' . $kind, static function () use ($db, $container, $kind, $name, $org): array {
            $person = (string) $db->createCommand("INSERT INTO core.persons(given_name,family_name,display_name)
                VALUES(:name,'Преподаватель',:name) RETURNING id::text", [':name' => $name])->queryScalar();
            $account = (string) $db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label,locale,timezone)
                VALUES(CAST(:person AS uuid),'human','active',:name,'ru-RU','Asia/Yekaterinburg') RETURNING id::text",
                [':person' => $person, ':name' => $name])->queryScalar();
            $email = $kind . '@education.example.test';
            $password = bin2hex(random_bytes(18));
            $db->createCommand("INSERT INTO core.auth_identifiers(account_id,identifier_type,value,normalized_value,is_primary,can_login,can_recover,verified_at)
                VALUES(CAST(:account AS uuid),'email',:email,:email,true,true,true,now())", [':account' => $account, ':email' => $email])->execute();
            $db->createCommand("INSERT INTO core.auth_password_credentials(account_id,password_hash,password_algorithm,must_change_password)
                VALUES(CAST(:account AS uuid),:hash,'argon2id',false)",
                [':account' => $account, ':hash' => $container->get(PasswordHasherInterface::class)->hash($password)])->execute();
            $db->createCommand('INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))',
                [':org' => $org, ':person' => $person])->execute();
            $db->createCommand("INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:account AS uuid),id FROM core.roles WHERE code='education.teacher'",
                [':account' => $account])->execute();
            $container->get(EducationStore::class)->grantTeacher($org, $account, $person);
            return ['id' => $account, 'personId' => $person, 'email' => $email, 'password' => $password, 'organizationId' => $org];
        });
    }
    $teacher = $accounts['teacher'];
    $identity = new AuthenticatedIdentity($teacher['id'], $teacher['personId'], $teacher['id'], [], [], 'ru-RU', 'Asia/Yekaterinburg');
    $scope = $container->get(EducationAccess::class)->scope($identity, $organization['id'], 'education.access');
    $education = $container->get(EducationService::class);
    $groups = [];
    foreach (['Первые шаги · Python', 'Робототехника · суббота'] as $index => $name) {
        $groups[] = $remember('group.' . $index, fn(): array => $education->write('create_group', $scope, ['name' => $name]));
    }
    $students = [];
    foreach (['Анна Кузнецова', 'Максим Александрович Соколов с длинной фамилией', 'Софья Петрова', 'Иван Орлов'] as $index => $name) {
        $students[] = $remember('student.' . $index, function () use ($container, $education, $scope, $groups, $index, $name): array {
            if (class_exists(App\Education\Application\CurriculumService::class)) {
                return $container->get(App\Education\Application\CurriculumService::class)->write('create_student', $scope,
                    ['givenName' => explode(' ', $name)[0], 'familyName' => substr($name, strpos($name, ' ') + 1),
                        'displayName' => $name, 'groupId' => $groups[$index < 2 ? 0 : 1]['id']]);
            }
            throw new RuntimeException('Install the Education foundation migration/service before seeding students.');
        });
    }
    $devices = $container->get(App\DeviceManagement\Application\DeviceService::class);
    $deviceScope = new App\DeviceManagement\Application\Scope($scope->organizationId, $scope->accountId, $scope->personId, $scope->locale, $scope->timezone);
    $classroom = $remember('classroom', fn(): array => $devices->write('create_classroom', $deviceScope, ['name' => 'Лаборатория · тестовый адаптер']));
    $computers = [];
    foreach ([1, 2] as $number) {
        $computers[] = $remember('computer.' . $number, function () use ($devices, $deviceScope, $classroom, $number): array {
            $device = $devices->write('create_device', $deviceScope, ['classroomId' => $classroom['id'], 'name' => 'Тестовый компьютер ' . $number, 'number' => (string) $number]);
            // Unenrolled fixtures are deliberately offline. A simulated computer
            // never claims native Windows readiness or successful Windows logon.
            return $device;
        });
    }
    $foundation = require __DIR__ . '/seed-curriculum.php';
    return ['organizationId' => $organization['id'], 'otherOrganizationId' => $otherOrganization['id'],
        'accounts' => $accounts, 'groups' => $groups, 'students' => $students, 'computers' => $computers,
        'curriculum' => $foundation($container, $remember, $scope, $education, $groups, $students, $accounts)];
});
$path = '/education-data/access.json';
file_put_contents($path . '.tmp', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
chmod($path . '.tmp', 0600);
rename($path . '.tmp', $path);
echo "Idempotent local fixtures saved; credentials remain in the isolated data directory.\n";
