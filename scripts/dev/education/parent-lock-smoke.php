<?php

declare(strict_types=1);

/** Deterministic two-process regression for concurrent invitation activation and staff revoke. */
require __DIR__ . '/guard.php';
educationFixtureDatabase();
require '/app/src/bootstrap.php';

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\ParentFamily;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

$container = (new HttpApplicationRunner(rootPath: '/app', environment: App\Environment::appEnv()))->getContainer();
$db = $container->get(ConnectionInterface::class);
App\Core\Shared\Infrastructure\TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
$family = $container->get(ParentFamily::class);
$identity = static fn(array $actor): AuthenticatedIdentity => new AuthenticatedIdentity(
    $actor['id'], $actor['personId'], '', [], [], 'en-US', 'UTC');

if (in_array('--worker', $argv, true)) {
    $input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(['pid' => (int) $db->createCommand('SELECT pg_backend_pid()')->queryScalar()], JSON_THROW_ON_ERROR) . "\n";
    flush();
    try {
        $family->activate($identity($input['actor']), ['code' => $input['code']]);
        echo json_encode(['activated' => true], JSON_THROW_ON_ERROR) . "\n";
    } catch (EducationError $error) {
        echo json_encode(['rejected' => true, 'status' => $error->status], JSON_THROW_ON_ERROR) . "\n";
    }
    exit(0);
}

$fixture = json_decode((string) file_get_contents('/education-data/access.json'), true, flags: JSON_THROW_ON_ERROR);
$demo = json_decode((string) file_get_contents('/education-data/education04-demo.json'), true, flags: JSON_THROW_ON_ERROR);
$teacher = $container->get(EducationAccess::class)->scope($identity($fixture['accounts']['teacher']), $fixture['organizationId'], 'education.family.manage');
$student = $demo['students'][1]['id'];
$guardian = $demo['parents'][0]['personId'];
$account = $db->createCommand('SELECT account_id::text FROM education.parent_accounts
    WHERE organization_id=CAST(:org AS uuid) AND guardian_person_id=CAST(:guardian AS uuid) AND revoked_at IS NULL',
    [':org' => $fixture['organizationId'], ':guardian' => $guardian])->queryScalar();
if (!is_string($account)) throw new RuntimeException('Run parent-api-smoke.py before the concurrency regression.');
$parent = ['id' => $account, 'personId' => $guardian];
$invite = $family->write('invite', $teacher, $student, $guardian);
$transaction = $db->beginTransaction();
$process = null;
$pipes = [];
try {
    $db->createCommand('SELECT id FROM education.students WHERE id=CAST(:student AS uuid) FOR UPDATE', [':student' => $student])->queryOne();
    $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/app');
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated activation worker.');
    fwrite($pipes[0], json_encode(['actor' => $parent, 'code' => $invite['code']], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 8);
    $worker = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
    $waiting = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $waiting = $db->createCommand('SELECT 1 WHERE pg_backend_pid()=ANY(pg_blocking_pids(CAST(:pid AS integer)))',
            [':pid' => $worker['pid']])->queryScalar() !== false;
        if ($waiting) break;
        usleep(100000);
    }
    if (!$waiting) throw new RuntimeException('Activation worker did not reach the concurrent lock boundary.');
    // Before the fix, activation held this edge while waiting on the student's FK: NOWAIT failed.
    $db->createCommand('SELECT student_id FROM education.student_guardians WHERE student_id=CAST(:student AS uuid)
        AND guardian_person_id=CAST(:guardian AS uuid) FOR UPDATE NOWAIT', [':student' => $student, ':guardian' => $guardian])->queryOne();
    $family->write('revoke', $teacher, $student, $guardian);
    $transaction->commit();
    $transaction = null;
    $result = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
    if (($result['rejected'] ?? false) !== true || !in_array($result['status'], [403, 404, 409], true)) {
        throw new RuntimeException('Concurrent activation must lose access after the committed revoke.');
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Activation worker failed.');
    $process = null;
    // Restore only this fictional sibling link so the next smoke run can reuse the fixture.
    $family->write('confirm', $teacher, $student, $guardian);
    $next = $family->write('invite', $teacher, $student, $guardian);
    $family->activate($identity($parent), ['code' => $next['code']]);
    file_put_contents('/education-data/parent-lock-evidence.json', json_encode([
        'studentLockedBeforeFamily' => true, 'concurrentRevokeRejectedActivation' => true, 'fixtureRestored' => true,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "CONCURRENCY PASS: student-first locks, simultaneous revoke rejects activation, fictional fixture restored.\n";
} finally {
    $transaction?->rollBack();
    if (is_resource($process)) proc_terminate($process);
}
