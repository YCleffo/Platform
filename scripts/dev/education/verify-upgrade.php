<?php

declare(strict_types=1);

// Additive migration compatibility, on a newly named database in the dedicated
// local PostgreSQL only. Existing databases and files are never reset/dropped.
require __DIR__ . '/guard.php';
$server = educationFixtureDatabase();
$database = 'education_upgrade_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$server->exec('CREATE DATABASE "' . $database . '"');
$db = new PDO('pgsql:host=postgres-test;port=5432;dbname=' . $database, 'education_fixture',
    (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT current_database()')->fetchColumn() !== $database) throw new RuntimeException('Unexpected database.');
$db->beginTransaction();
try {
    $core = glob('/app/migrations/sql/*.sql') ?: []; sort($core, SORT_STRING);
    foreach ($core as $file) $db->exec((string) file_get_contents($file));
    $db->exec('CREATE SCHEMA education');
    $education = glob('/app/migrations/education/*.sql') ?: []; sort($education, SORT_STRING);
    foreach ($education as $file) if (basename($file) < '008_') $db->exec((string) file_get_contents($file));
    $org = $db->query("INSERT INTO core.organizations(code,name) VALUES('upgrade.fixture','Legacy organization') RETURNING id::text")->fetchColumn();
    $teacher = $db->query("INSERT INTO core.persons(given_name,family_name) VALUES('Legacy','Teacher') RETURNING id::text")->fetchColumn();
    $studentPerson = $db->query("INSERT INTO core.persons(given_name,family_name) VALUES('Legacy','Student') RETURNING id::text")->fetchColumn();
    $account = $db->query("INSERT INTO core.accounts(person_id,account_type,status) VALUES('$teacher','human','active') RETURNING id::text")->fetchColumn();
    $db->exec("INSERT INTO education.teachers(organization_id,account_id,person_id) VALUES('$org','$account','$teacher')");
    $group = $db->query("INSERT INTO education.groups(organization_id,owner_account_id,owner_person_id,name) VALUES('$org','$account','$teacher','Legacy group') RETURNING id::text")->fetchColumn();
    $student = $db->query("INSERT INTO education.students(organization_id,owner_account_id,person_id) VALUES('$org','$account','$studentPerson') RETURNING id::text")->fetchColumn();
    $db->exec("INSERT INTO education.group_students(group_id,student_id,organization_id,owner_account_id) VALUES('$group','$student','$org','$account')");
    $lesson = $db->query("INSERT INTO education.lessons(organization_id,owner_account_id,owner_person_id,group_id,title,starts_at,ends_at,status,completed_at)
        VALUES('$org','$account','$teacher','$group','Legacy lesson','2001-01-01T10:00:00Z','2001-01-01T11:00:00Z','completed',now()) RETURNING id::text")->fetchColumn();
    $db->exec("INSERT INTO education.attendance(lesson_id,student_id,organization_id,owner_account_id,status) VALUES('$lesson','$student','$org','$account','present')");
    $db->exec("INSERT INTO education.lesson_results(organization_id,owner_account_id,lesson_id,student_id,status) VALUES('$org','$account','$lesson','$student','mastered')");
    $snapshot = static function (PDO $db): array {
        $data = [];
        foreach (['core.persons', 'core.accounts', 'core.organizations', 'education.groups', 'education.students',
            'education.group_students', 'education.attendance', 'education.lesson_results'] as $table) {
            $data[$table] = $db->query("SELECT to_jsonb(t)::text FROM $table t ORDER BY to_jsonb(t)::text")->fetchAll(PDO::FETCH_COLUMN);
        }
        $data['lessons'] = $db->query('SELECT id,group_id,title,starts_at,ends_at,status,completed_at FROM education.lessons ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return $data;
    };
    $before = $snapshot($db);
    foreach ($education as $file) if (basename($file) >= '008_') $db->exec((string) file_get_contents($file));
    if ($before !== $snapshot($db)) throw new RuntimeException('A legacy record changed during migration.');
    if ((int) $db->query("SELECT count(*) FROM education.group_teachers WHERE group_id='$group' AND account_id='$account'")->fetchColumn() !== 1) {
        throw new RuntimeException('Legacy teacher assignment was not preserved.');
    }
    $db->commit();
    echo "PASS: 008/009 preserve legacy Core identities, organization, group, enrollment, lesson, attendance and result; owner assignment backfilled. Evidence database: $database\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    throw $error;
}
