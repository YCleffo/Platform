<?php

declare(strict_types=1);

return static function ($container, callable $remember, $scope, $education, array $groups, array $students, array $accounts): array {
    $curriculum = $container->get(App\Education\Application\CurriculumService::class);
    $program = $remember('program', fn(): array => $curriculum->write('create_program', $scope,
        ['name' => 'Основы программирования', 'description' => 'Локальный учебный пример. Прогресс выводится из сохранённых занятий и результатов.']));
    $modules = [];
    $curriculumLessons = [];
    foreach (['Знакомство с алгоритмами', 'Практика Python'] as $index => $name) {
        $module = $remember('module.' . $index, fn(): array => $curriculum->write('create_curriculum_module', $scope,
            ['name' => $name, 'description' => '', 'position' => $index], $program['id']));
        $modules[] = $module;
        foreach (['Первое знакомство', 'Самостоятельная работа'] as $position => $title) {
            $curriculumLessons[] = $remember('curriculum.lesson.' . $index . '.' . $position,
                fn(): array => $curriculum->write('create_curriculum_lesson', $scope,
                    ['title' => $name . ' · ' . $title, 'description' => 'Задачи и обсуждение результатов.', 'position' => $position], $module['id']));
        }
    }
    foreach ($groups as $index => $group) {
        $remember('assignment.' . $index, fn(): array => $curriculum->write('assign_program', $scope, ['programId' => $program['id']], $group['id']));
    }
    $remember('shared.teachers', fn(): array => $curriculum->write('set_group_teachers', $scope,
        ['accountIds' => [$accounts['teacher']['id'], $accounts['colleague']['id']]], $groups[0]['id']));
    $lessons = [];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ([-2, -1, 1, 3] as $index => $hours) {
        $group = $groups[$index % 2];
        $lessons[] = $remember('scheduled.lesson.' . $index, function () use ($education, $scope, $group, $curriculumLessons, $index, $hours, $now, $students): array {
            $start = $now->modify(($hours >= 0 ? '+' : '') . $hours . ' hours');
            $lesson = $education->write('create_lesson', $scope, ['groupId' => $group['id'],
                'title' => $curriculumLessons[$index % 2]['title'], 'curriculumLessonId' => $curriculumLessons[$index % 2]['id'],
                'startsAt' => $start->format(DATE_ATOM), 'endsAt' => $start->modify('+45 minutes')->format(DATE_ATOM),
                'location' => 'Лаборатория']);
            if ($hours < 0) {
                $education->write('complete_lesson', $scope, [], $lesson['id']);
                foreach (array_slice($students, $index % 2 === 0 ? 0 : 2, 2) as $position => $student) {
                    $education->write('mark_attendance', $scope, ['status' => $position === 0 ? 'present' : 'absent'], $lesson['id'], $student['id']);
                    $education->write('save_lesson_result', $scope,
                        ['status' => $position === 0 ? 'mastered' : 'developing', 'comment' => 'Сохранённый учебный результат локального примера.'], $lesson['id'], $student['id']);
                }
                $education->write('save_lesson_report', $scope,
                    ['topic' => $lesson['title'], 'summary' => 'Обсудили алгоритм и выполнили практическое задание.', 'teacherComment' => null], $lesson['id']);
                $lesson = $education->read('lesson', $scope, [], $lesson['id']);
            }
            return $lesson;
        });
    }
    $studentAccount = $remember('student.account', function () use ($container, $scope, $education, $students): array {
        $db = $container->get(Yiisoft\Db\Connection\ConnectionInterface::class);
        $person = $students[0]['personId'];
        $id = (string) $db->createCommand("INSERT INTO core.accounts(person_id,account_type,status,label,locale,timezone)
            VALUES(CAST(:person AS uuid),'human','active','Локальный ученик','ru-RU','Asia/Yekaterinburg') RETURNING id::text", [':person' => $person])->queryScalar();
        $email = 'student@education.example.test'; $password = bin2hex(random_bytes(18));
        $db->createCommand("INSERT INTO core.auth_identifiers(account_id,identifier_type,value,normalized_value,is_primary,can_login,can_recover,verified_at)
            VALUES(CAST(:account AS uuid),'email',:email,:email,true,true,true,now())", [':account' => $id, ':email' => $email])->execute();
        $db->createCommand("INSERT INTO core.auth_password_credentials(account_id,password_hash,password_algorithm,must_change_password)
            VALUES(CAST(:account AS uuid),:hash,'argon2id',false)", [':account' => $id,
                ':hash' => $container->get(App\Core\Auth\Application\PasswordHasherInterface::class)->hash($password)])->execute();
        $db->createCommand("INSERT INTO core.account_roles(account_id,role_id) SELECT CAST(:account AS uuid),id FROM core.roles WHERE code='education.student'", [':account' => $id])->execute();
        $invite = $education->write('invite_student', $scope, [], $students[0]['id']);
        $identity = new App\Core\Auth\Application\AuthenticatedIdentity($id, $person, $id, [], [], 'ru-RU', 'Asia/Yekaterinburg');
        $education->activateInvite($identity, $person, ['code' => $invite['code']]);
        return ['id' => $id, 'personId' => $person, 'email' => $email, 'password' => $password, 'organizationId' => $scope->organizationId];
    });
    $material = $remember('lesson.material', function () use ($education, $scope, $lessons): array {
        $bytes = "Local Education lesson material.\nAlgorithms and practice.\n";
        return $education->upload($scope, new HttpSoft\Message\UploadedFile((new HttpSoft\Message\StreamFactory())->createStream($bytes),
            strlen($bytes), UPLOAD_ERR_OK, 'lesson-material.txt', 'text/plain'), $lessons[0]['id'], false);
    });
    return ['program' => $program, 'modules' => $modules, 'lessons' => $curriculumLessons, 'scheduled' => $lessons,
        'studentAccount' => $studentAccount, 'material' => $material];
};
