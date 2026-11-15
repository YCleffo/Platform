<?php

declare(strict_types=1);

require __DIR__ . '/guard.php';
educationFixtureDatabase();
require '/app/src/bootstrap.php';

use App\Core\Auth\Application\{AuthenticatedIdentity, PasswordHasherInterface};
use App\Core\Person\Application\{CreatePerson, CreatePersonCommand, CreatePersonContact, CreatePersonContactCommand};
use App\Education\Application\{CurriculumService, EducationAccess, EducationService, HomeworkActivityService, HomeworkService};
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$container = (new HttpApplicationRunner(rootPath: '/app', environment: App\Environment::appEnv()))->getContainer();
$db = $container->get(ConnectionInterface::class);
App\Core\Shared\Infrastructure\TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
$access = json_decode((string) file_get_contents('/education-data/access.json'), true, flags: JSON_THROW_ON_ERROR);
$teacher = $access['accounts']['teacher'];
$identity = static fn(array $a) => new AuthenticatedIdentity($a['id'], $a['personId'], $a['id'], [], [], 'ru-RU', 'Asia/Yekaterinburg');
$scope = $container->get(EducationAccess::class)->scope($identity($teacher), $access['organizationId'], 'education.access');
$education = $container->get(EducationService::class);
$homework = $container->get(HomeworkService::class);
$curriculum = $container->get(CurriculumService::class);
$uuid = static fn(): string => (string) $db->createCommand('SELECT uuidv7()::text')->queryScalar();
$result = $db->transaction(function () use ($db, $container, $access, $scope, $identity, $education, $homework, $curriculum, $uuid): array {
    $db->createCommand('SELECT pg_advisory_xact_lock(1846202614)')->queryScalar();
    $remember = static function (string $name, callable $create) use ($db): array {
        $key = 'education04.' . $name;
        $saved = $db->createCommand('SELECT value::text FROM public.education_local_fixtures WHERE name=:key', [':key' => $key])->queryScalar();
        if (is_string($saved)) return json_decode($saved, true, flags: JSON_THROW_ON_ERROR);
        $value = $create();
        $db->createCommand('INSERT INTO public.education_local_fixtures(name,value) VALUES(:key,CAST(:value AS jsonb))',
            [':key' => $key, ':value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)])->execute();
        return $value;
    };
    $groups = $access['groups'];
    $students = $access['students'];
    $given = ['Артём','Алиса','Михаил','Полина','Даниил','Варвара','Никита','Ева','Кирилл','Дарья','Лев','Мария','Егор','Виктория','Тимофей','Арина','Матвей','Ксения'];
    $family = ['Смирнов','Волкова','Морозов','Новикова','Фёдоров','Михайлова','Лебедев','Семёнова','Павлов','Егорова','Козлов','Степанова','Захаров','Никитина','Белов','Гусева','Крылов','Тихонова'];
    foreach (['Python · начинающие','Разработка игр · Scratch','Веб-мастерская · HTML и CSS'] as $index => $name) {
        $group = $remember('group.' . $index, fn(): array => $education->write('create_group', $scope, ['name' => $name]));
        $groups[] = $group;
        $remember('program.' . $index, fn(): array => $curriculum->write('assign_program', $scope,
            ['programId' => $access['curriculum']['program']['id']], $group['id']));
        for ($position = 0; $position < 6; $position++) {
            $n = $index * 6 + $position;
            $students[] = $remember('student.' . $n, fn(): array => $curriculum->write('create_student', $scope,
                ['givenName' => $given[$n], 'familyName' => $family[$n], 'birthDate' => (2013 + $n % 4) . '-05-12', 'groupId' => $group['id']]));
        }
    }
    $studentAccounts = [];
    $parents = [];
    foreach ($students as $index => $student) {
        $parents[] = $remember('parent.' . $student['id'], function () use ($db, $container, $student, $index, $scope): array {
            $name = ($index % 2 === 0 ? 'Елена' : 'Александр') . ' · семья ' . ($index + 1);
            $person = $container->get(CreatePerson::class)->execute(new CreatePersonCommand(
                $index % 2 === 0 ? 'Елена' : 'Александр', 'Демонстрационная', null, $name, null));
            $db->createCommand('INSERT INTO core.organization_memberships(organization_id,person_id) VALUES(CAST(:org AS uuid),CAST(:person AS uuid))',
                [':org' => $scope->organizationId, ':person' => $person->id])->execute();
            $container->get(CreatePersonContact::class)->execute(new CreatePersonContactCommand($person->id, 'email',
                'parent' . ($index + 1) . '@education.example.test', 'Демонстрационный контакт', true));
            $db->createCommand('INSERT INTO education.student_guardians(organization_id,student_id,guardian_person_id,relationship)
                VALUES(CAST(:org AS uuid),CAST(:student AS uuid),CAST(:parent AS uuid),:relationship)',
                [':org' => $scope->organizationId, ':student' => $student['id'], ':parent' => $person->id,
                    ':relationship' => $index % 2 === 0 ? 'mother' : 'father'])->execute();
            return ['personId' => $person->id, 'studentId' => $student['id'], 'name' => $name];
        });
        $studentAccounts[] = $remember('account.' . $student['id'], function () use ($db, $container, $education, $scope, $student, $index, $access, $identity): array {
            if ($index === 0) return $access['curriculum']['studentAccount'];
            $account = (string) $db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label,locale,timezone)
                VALUES(CAST(:person AS uuid),'human','active',:name,'ru-RU','Asia/Yekaterinburg') RETURNING id::text",
                [':person' => $student['personId'], ':name' => 'Демо · ' . ($student['name'] ?? 'ученик')])->queryScalar();
            $email = 'learner' . ($index + 1) . '@education.example.test';
            $password = bin2hex(random_bytes(18));
            $db->createCommand("INSERT INTO core.auth_identifiers(account_id,identifier_type,value,normalized_value,is_primary,can_login,can_recover,verified_at)
                VALUES(CAST(:id AS uuid),'email',:email,:email,true,true,true,now())", [':id' => $account, ':email' => $email])->execute();
            $db->createCommand("INSERT INTO core.auth_password_credentials(account_id,password_hash,password_algorithm,must_change_password)
                VALUES(CAST(:id AS uuid),:hash,'argon2id',false)", [':id' => $account, ':hash' => $container->get(PasswordHasherInterface::class)->hash($password)])->execute();
            $result = ['id' => $account, 'personId' => $student['personId'], 'email' => $email, 'password' => $password, 'organizationId' => $scope->organizationId];
            $invite = $education->write('invite_student', $scope, [], $student['id']);
            $education->activateInvite($identity($result), $student['personId'], ['code' => $invite['code']]);
            return $result;
        });
    }
    $studentRole = (string) $db->createCommand("SELECT id::text FROM core.roles WHERE code='education.student'")->queryScalar();
    foreach ($studentAccounts as $account) $container->get(App\Core\Rbac\Application\RoleRepository::class)
        ->assign($account['id'], $studentRole, $scope->accountId);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach (array_slice($groups, 2) as $index => $group) {
        foreach ([-1, 1] as $day) $remember('lesson.' . $index . '.' . $day, function () use ($education, $scope, $group, $day, $now, $index, $db): array {
            $start = $now->modify(($day > 0 ? '+' : '') . $day . ' day')->setTime(8 + $index * 2, 0);
            $lesson = $education->write('create_lesson', $scope, ['groupId' => $group['id'], 'title' => 'Практика алгоритмов · демо',
                'startsAt' => $start->format(DATE_ATOM), 'endsAt' => $start->modify('+60 minutes')->format(DATE_ATOM), 'location' => 'Учебная лаборатория']);
            if ($day < 0) {
                $education->write('complete_lesson', $scope, [], $lesson['id']);
                $members = $db->createCommand('SELECT student_id::text FROM education.group_students WHERE group_id=CAST(:id AS uuid)', [':id' => $group['id']])->queryAll();
                foreach ($members as $n => $member) {
                    $education->write('mark_attendance', $scope, ['status' => $n === 4 ? 'absent' : 'present'], $lesson['id'], $member['student_id']);
                    $education->write('save_lesson_result', $scope, ['status' => $n % 2 ? 'developing' : 'mastered', 'comment' => 'Демонстрационный учебный результат.'], $lesson['id'], $member['student_id']);
                }
            }
            return $lesson;
        });
    }
    $studentScope = $container->get(EducationAccess::class)->studentScope($identity($studentAccounts[0]), $scope->organizationId, 'education.student.use');
    $assignments = [];
    foreach (['new','in_progress','submitted','returned','reviewed','overdue','typing','files','archived'] as $kind) {
        $assignments[] = $remember('homework.' . $kind, function () use ($homework, $scope, $studentScope, $groups, $now, $kind, $uuid, $container, $education): array {
            $title = match ($kind) { 'new' => 'Найди алгоритмы вокруг себя', 'in_progress' => 'Объясни цикл своими словами',
                'submitted' => 'Составь план небольшой игры', 'returned' => 'Уточни условие задачи', 'reviewed' => 'Последовательность действий',
                'overdue' => 'Повторение: команды и события', 'typing' => 'Typing · точность набора', 'files' => 'Прикрепи файл своего проекта', default => 'Архив · первая практика' };
            $draft = $homework->write('create', $scope, ['title' => $title, 'description' => 'Демонстрационное задание для знакомства с учебным процессом.',
                'instructions' => $kind === 'typing' ? 'Запусти тренажёр, затем отправь сохранённый результат преподавателю.' : 'Выполни задание и объясни ход решения. Можно приложить разрешённый файл.',
                'type' => $kind === 'typing' ? 'typing' : ($kind === 'files' ? 'files' : 'text'), 'groupId' => $groups[0]['id'],
                'dueAt' => $now->modify($kind === 'overdue' ? '-1 day' : '+3 days')->format(DATE_ATOM), 'allowLate' => $kind === 'overdue',
                'activityConfig' => ['durationSeconds' => 30, 'language' => 'ru'], 'maxAttempts' => 3, 'attemptPolicy' => 'best']);
            $id = $draft['assignment']['id'];
            $published = $homework->write('publish', $scope, ['version' => $draft['assignment']['version']], $id);
            if (in_array($kind, ['in_progress','submitted','returned','reviewed','archived'], true)) {
                $answer = $homework->write('save_draft', $studentScope, ['version' => 0, 'text' => 'Алгоритм — это понятная последовательность шагов. Например: выбрать цель, составить план, выполнить шаги и проверить результат.'], $id);
                if ($kind !== 'in_progress') {
                    $answer = $homework->write('submit', $studentScope, ['version' => $answer['submission']['version'], 'idempotencyKey' => $uuid()], $id);
                    if (in_array($kind, ['returned','reviewed','archived'], true)) $homework->write('review', $scope,
                        ['version' => $answer['submission']['version'], 'idempotencyKey' => $uuid(), 'decision' => $kind === 'returned' ? 'returned' : 'accepted',
                            'resultStatus' => $kind === 'returned' ? null : 'mastered', 'comment' => $kind === 'returned' ? 'Добавь пример и объясни, когда завершится цикл.' : 'Хороший порядок шагов, решение принято.'], $answer['submission']['id']);
                }
            }
            if ($kind === 'archived') {
                $closed = $homework->write('close', $scope, ['version' => $published['assignment']['version']], $id);
                $homework->write('archive', $scope, ['version' => $closed['assignment']['version']], $id);
            }
            return ['id' => $id, 'kind' => $kind];
        });
    }
    return ['groups' => $groups, 'students' => $students, 'parents' => $parents, 'studentAccounts' => $studentAccounts, 'homework' => $assignments];
});
$path = '/education-data/education04-demo.json';
file_put_contents($path . '.tmp', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($path . '.tmp', 0600);
rename($path . '.tmp', $path);
echo 'Demo data saved: ' . count($result['groups']) . ' groups, ' . count($result['students']) . ' students, '
    . count($result['parents']) . ' parents, ' . count($result['homework']) . " homework. Credentials stay private.\n";
