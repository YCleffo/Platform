<?php

declare(strict_types=1);

use App\DeviceManagement\Application\Scope;
use App\Storage\Application\StorageScope;

require __DIR__ . '/http-bootstrap.php';

if (PHP_SAPI !== 'cli') throw new RuntimeException('The classroom fixture is CLI-only.');
$runtime = classroomHttpRuntime();
$db = $runtime['db'];
$devices = $runtime['deviceStore'];
$storage = $runtime['storageStore'];
$path = $runtime['runtime'] . '/fixture.json';
$command = $argv[1] ?? '';
$uuid = static fn(): string => (string) $db->createCommand('SELECT uuidv7()::text')->queryScalar();

if ($command === 'init') {
    if (is_file($path)) throw new RuntimeException('Fixture already exists; reuse it or choose a fresh dedicated runtime.');
    // A minimal committed fixture in the explicitly named test database. The
    // production migrations remain the source of DeviceManagement/Storage DDL.
    $db->getActivePdo()->exec('CREATE SCHEMA IF NOT EXISTS core; CREATE SCHEMA IF NOT EXISTS education;
        CREATE TABLE IF NOT EXISTS core.modules(code text PRIMARY KEY,name text,description text,version text,enabled bool);
        CREATE TABLE IF NOT EXISTS core.persons(id uuid PRIMARY KEY DEFAULT uuidv7(),given_name text,family_name text,middle_name text,display_name text);
        ALTER TABLE core.persons ADD COLUMN IF NOT EXISTS display_name text;
        CREATE TABLE IF NOT EXISTS education.students(id uuid PRIMARY KEY DEFAULT uuidv7(),person_id uuid,organization_id uuid,owner_account_id uuid)');
    (new App\DeviceManagement\Infrastructure\DeviceMigrationRunner($db, $runtime['root'] . '/migrations/device_management'))->apply();
    (new App\Storage\Infrastructure\StorageMigrationRunner($db, $runtime['root'] . '/migrations/storage'))->apply();
    $fixture = $db->transaction(static function () use ($runtime, $db, $uuid, $devices, $storage): array {
        $scope = new Scope($uuid(), $uuid(), $uuid(), 'en-US', 'UTC');
        $classroom = $devices->createClassroom($scope, 'Classroom HTTP protocol scenario');
        $device = $devices->createDevice($scope, $classroom['id'], 'HTTP Test PC', 'HTTP-1');
        $code = bin2hex(random_bytes(12));
        $devices->createEnrollment($scope, $device['id'], hash('sha256', $code), (new DateTimeImmutable('+2 hours'))->format(DateTimeImmutable::ATOM));
        $students = [];
        foreach (['A', 'B'] as $name) {
            $person = $uuid();
            $student = $uuid();
            $db->createCommand('INSERT INTO core.persons(id,given_name,family_name,display_name) VALUES(CAST(:id AS uuid),:name,\'HTTP Test\',:display)',
                [':id' => $person, ':name' => $name, ':display' => 'Student ' . $name])->execute();
            $db->createCommand('INSERT INTO education.students(id,person_id,organization_id,owner_account_id)
                VALUES(CAST(:student AS uuid),CAST(:person AS uuid),CAST(:org AS uuid),CAST(:actor AS uuid))',
                [':student' => $student, ':person' => $person, ':org' => $scope->organizationId, ':actor' => $scope->accountId])->execute();
            $content = $name . ' original server document';
            $key = $uuid();
            $bytes = fopen('php://temp', 'w+b');
            if ($bytes === false) throw new RuntimeException('Cannot prepare fixture bytes.');
            fwrite($bytes, $content);
            rewind($bytes);
            $runtime['files']->write($key, $bytes);
            fclose($bytes);
            $storage->storeVersion(new StorageScope($scope->organizationId, $scope->accountId, $person, 'en-US', 'UTC', $student),
                'work.txt', strlen($content), hash('sha256', $content), $key, null);
            $students[$name] = ['id' => $student, 'personId' => $person, 'name' => 'Student ' . $name,
                'initialPath' => 'work.txt', 'initialContent' => $content];
        }
        return ['baseUrl' => 'http://127.0.0.1:18762', 'deviceId' => $device['id'], 'enrollmentCode' => $code,
            'classroomId' => $classroom['id'], 'organizationId' => $scope->organizationId,
            'teacherAccountId' => $scope->accountId, 'teacherPersonId' => $scope->personId, 'students' => $students];
    });
    if (file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === false) throw new RuntimeException('Cannot save fixture.');
    chmod($path, 0600);
    echo json_encode(['created' => true, 'fixturePath' => $path, 'deviceId' => $fixture['deviceId']], JSON_THROW_ON_ERROR) . "\n";
    exit;
}

if (!is_file($path)) throw new RuntimeException('Initialize the dedicated HTTP fixture first.');
$fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$scope = new Scope($fixture['organizationId'], $fixture['teacherAccountId'], $fixture['teacherPersonId'], 'en-US', 'UTC');
$device = $fixture['deviceId'];
$result = match ($command) {
    'assign' => (static function () use ($argv, $fixture, $scope, $device, $devices): array {
        $student = $fixture['students'][$argv[2] ?? ''] ?? throw new RuntimeException('Choose fixture student A or B.');
        return $devices->startSession($scope, $device, $student['id'], ['personId' => $student['personId'], 'name' => $student['name']]);
    })(),
    'end' => (static function () use ($devices, $scope, $device): array {
        $session = $devices->activeSession($device) ?? throw new RuntimeException('No active fixture session.');
        $devices->requestSessionEnd($scope, $device, $session['id']);
        return ['sessionId' => $session['id'], 'ending' => true];
    })(),
    'browser-edit' => (static function () use ($argv, $fixture, $scope, $runtime): array {
        $student = $fixture['students'][$argv[2] ?? 'A'] ?? throw new RuntimeException('Choose fixture student A or B.');
        $studentScope = new StorageScope($scope->organizationId, $scope->accountId, $student['personId'], 'en-US', 'UTC', $student['id']);
        $ticket = $runtime['storageService']->write('ticket', $studentScope, ['purpose' => 'upload', 'path' => 'work.txt']);
        $content = 'A browser conflict version';
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Cannot prepare fixture content.');
        fwrite($stream, $content); rewind($stream);
        try { return $runtime['storageService']->uploadWithTicket($ticket['token'], $stream, hash('sha256', $content)); }
        finally { fclose($stream); }
    })(),
    'resolve' => (static function () use ($fixture, $scope, $device, $devices, $storage): array {
        $session = $devices->activeSession($device) ?? throw new RuntimeException('No active fixture session.');
        $student = array_values(array_filter($fixture['students'], static fn(array $student): bool => $student['id'] === $session['studentId']))[0]
            ?? throw new RuntimeException('Session belongs to another fixture.');
        $studentScope = new StorageScope($scope->organizationId, $scope->accountId, $student['personId'], 'en-US', 'UTC', $student['id']);
        $resolved = 0;
        foreach ($storage->conflicts($studentScope, 0) as $conflict) {
            if ($conflict['resolvedAt'] === null) { $storage->resolveConflict($studentScope, $conflict['id'], 'keep_server'); $resolved++; }
        }
        return ['resolved' => $resolved];
    })(),
    'retry' => $devices->retryRecovery($scope, $device, null),
    'status' => ['device' => $devices->device($scope, $device), 'session' => $devices->activeSession($device),
        'details' => $devices->lifecycleDetails($scope, $device)],
    'events' => ['items' => $devices->deviceEvents($scope, $device, 0)],
    'revoke' => (static function () use ($devices, $scope, $device): array {
        $devices->revokeDevice($scope, $device);
        return ['revoked' => true];
    })(),
    default => throw new RuntimeException('Allowed fixture commands: init, assign A|B, end, browser-edit A|B, resolve, retry, status, events, revoke.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

