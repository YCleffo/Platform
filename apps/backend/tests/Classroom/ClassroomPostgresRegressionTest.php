<?php

declare(strict_types=1);

namespace App\Tests\Classroom;

use App\DeviceManagement\Application\DeviceError;
use App\DeviceManagement\Application\Scope;
use App\DeviceManagement\Infrastructure\PostgresDeviceStore;
use App\Storage\Application\Mutation;
use App\Storage\Application\StorageError;
use App\Storage\Application\StorageScope;
use App\Storage\Application\StorageService;
use App\Tests\Storage02\ObservedS3Objects;

require_once dirname(__DIR__) . '/Storage02/ObservedS3Objects.php';
use App\Storage\Infrastructure\PostgresStorageStore;
use PHPUnit\Framework\TestCase;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;
use Yiisoft\Db\Transaction\TransactionInterface;

/** Run explicitly against the separately created classroom02_test database only. */
final class ClassroomPostgresRegressionTest extends TestCase
{
    private static Connection $db;
    private TransactionInterface $transaction;
    private PostgresDeviceStore $devices;
    private PostgresStorageStore $storage;
    private StorageService $service;
    private Scope $scope;
    private StorageScope $storageScope;
    private string $student;
    private ObservedS3Objects $files;

    public static function setUpBeforeClass(): void
    {
        self::assertSame('classroom02_test', getenv('CLASSROOM_TEST_DATABASE'), 'Explicit isolated database opt-in required.');
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        self::$db = new Connection(new Driver("pgsql:host=$host;port=$port;dbname=classroom02_test", getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: ''), new SchemaCache(new ArrayCache()));
        self::assertSame('classroom02_test', self::$db->createCommand('SELECT current_database()')->queryScalar());
        self::$db->getActivePdo()->exec('CREATE SCHEMA IF NOT EXISTS core; CREATE SCHEMA IF NOT EXISTS education;
            CREATE SCHEMA IF NOT EXISTS device_management; CREATE SCHEMA IF NOT EXISTS storage;
            CREATE TABLE IF NOT EXISTS core.modules(code text PRIMARY KEY,name text,description text,version text,enabled bool);
            CREATE TABLE IF NOT EXISTS core.persons(id uuid PRIMARY KEY DEFAULT uuidv7(),given_name text,family_name text,middle_name text,display_name text);
            CREATE TABLE IF NOT EXISTS education.students(id uuid PRIMARY KEY DEFAULT uuidv7(),person_id uuid,organization_id uuid,owner_account_id uuid);
            ALTER TABLE core.persons ADD COLUMN IF NOT EXISTS display_name text');
        (new \App\DeviceManagement\Infrastructure\DeviceMigrationRunner(self::$db, dirname(__DIR__, 2) . '/migrations/device_management'))->apply();
        (new \App\Storage\Infrastructure\StorageMigrationRunner(self::$db, dirname(__DIR__, 2) . '/migrations/storage'))->apply();
    }

    protected function setUp(): void
    {
        $this->transaction = self::$db->beginTransaction();
        $this->devices = new PostgresDeviceStore(self::$db, allowTestAdapter: true);
        $this->storage = new PostgresStorageStore(self::$db);
        $this->scope = new Scope($this->id(), $this->id(), $this->id(), 'en-US', 'UTC');
        $this->student = $this->id();
        self::$db->createCommand('INSERT INTO core.persons(id,given_name,family_name) VALUES(CAST(:id AS uuid),\'Student\',\'Regression\')', [':id' => $this->scope->personId])->execute();
        self::$db->createCommand('INSERT INTO education.students(id,person_id,organization_id,owner_account_id)
            VALUES(CAST(:student AS uuid),CAST(:person AS uuid),CAST(:org AS uuid),CAST(:actor AS uuid))', [
            ':student' => $this->student, ':person' => $this->scope->personId, ':org' => $this->scope->organizationId, ':actor' => $this->scope->accountId,
        ])->execute();
        $this->storageScope = new StorageScope($this->scope->organizationId, $this->scope->accountId, $this->scope->personId, 'en-US', 'UTC', $this->student);
        $this->files = new ObservedS3Objects();
        $this->service = new StorageService($this->storage, $this->files, new class implements Mutation {
            public function run(StorageScope $scope, string $operation, callable $action): array
            {
                return $action();
            }
        }, $this->devices);
    }

    protected function tearDown(): void
    {
        if ($this->transaction->isActive()) {
            $this->transaction->rollBack();
        }
        $this->files->removeOwned();
    }

    public function testMachineReadinessAndSingleAssignmentAreEnforcedByStore(): void
    {
        [$device] = $this->enrolledDevice();
        $this->deviceRejects('device_not_ready', fn() => $this->start($device));
        $this->devices->heartbeat($device, json_encode(['machineReady' => true, 'hasUnfinishedDocuments' => true], JSON_THROW_ON_ERROR));
        $this->deviceRejects('device_not_ready', fn() => $this->start($device));
        $this->ready($device);
        $session = $this->start($device);
        self::assertSame('AUTHORIZED', $session['status']);
        try {
            $this->start($device);
            self::fail('Second assignment was allowed.');
        } catch (DeviceError $error) {
            self::assertSame(409, $error->status);
        }
        self::assertSame(1, (int) self::$db->createCommand('SELECT count(*) FROM device_management.device_sessions WHERE device_id=CAST(:id AS uuid)', [':id' => $device])->queryScalar());
    }

    public function testIdleDeviceWithExpiredHeartbeatAppearsOfflineInListAndDetail(): void
    {
        [$device, $classroom] = $this->enrolledDevice();
        $this->ready($device);
        self::assertSame('AVAILABLE', $this->devices->device($this->scope, $device)['status']);
        self::assertSame([$device], array_column($this->devices->devices($this->scope, 0, $classroom, 'AVAILABLE'), 'id'));

        self::$db->createCommand("UPDATE device_management.devices SET last_seen_at=now()-interval '91 seconds' WHERE id=CAST(:id AS uuid)", [':id' => $device])->execute();

        self::assertSame('OFFLINE', $this->devices->device($this->scope, $device)['status']);
        self::assertSame([], $this->devices->devices($this->scope, 0, $classroom, 'AVAILABLE'));
        self::assertSame([$device], array_column($this->devices->devices($this->scope, 0, $classroom, 'OFFLINE'), 'id'));
        self::assertNotContains('assign', $this->devices->lifecycleDetails($this->scope, $device)['availableActions']);
        $this->deviceRejects('device_not_ready', fn() => $this->start($device));
    }

    public function testActiveClassroomCannotBeArchivedOrMovedAndForeignTeacherCannotEnd(): void
    {
        [$device, $classroom] = $this->enrolledDevice();
        $this->ready($device);
        $session = $this->start($device);
        $this->deviceRejects('classroom_busy', fn() => $this->devices->archiveClassroom($this->scope, $classroom));
        $this->deviceRejects('device_busy', fn() => $this->devices->updateDevice($this->scope, $device, null, 'PC', '9'));
        self::assertSame($classroom, $this->devices->device($this->scope, $device)['classroomId']);
        self::assertNull($this->devices->classroom($this->scope, $classroom)['archivedAt']);
        $other = new Scope($this->scope->organizationId, $this->id(), $this->id(), 'en-US', 'UTC');
        $this->deviceRejects('not_found', fn() => $this->devices->requestSessionEnd($other, $device, $session['id']));
        self::assertSame('AUTHORIZED', $this->devices->activeSession($device)['status']);
    }

    public function testNativeActiveAndStorageCleanupNeedIndependentConfirmations(): void
    {
        [$device] = $this->enrolledDevice();
        $this->ready($device);
        $session = $this->start($device);
        $this->devices->transitionSession($device, $session['id'], 'PREPARING');
        $this->devices->transitionSession($device, $session['id'], 'WINDOWS_PROFILE_READY', ['profilePrepared' => true]);
        $this->devices->transitionSession($device, $session['id'], 'SYNCING_IN');
        $this->deviceRejects('logon_not_confirmed', fn() => $this->devices->transitionSession($device, $session['id'], 'ACTIVE', ['profilePrepared' => true]));
        $this->devices->transitionSession($device, $session['id'], 'ACTIVE', ['profilePrepared' => true, 'windowsLogonConfirmed' => true]);
        $this->devices->requestSessionEnd($this->scope, $device, $session['id']);
        $this->devices->transitionSession($device, $session['id'], 'ENDING');
        $this->devices->transitionSession($device, $session['id'], 'SYNCING_OUT', ['sessionEnded' => true]);
        $this->deviceRejects('storage_not_confirmed', fn() => $this->devices->transitionSession($device, $session['id'], 'STORAGE_CONFIRMED', ['sessionEnded' => true, 'storageConfirmed' => true]));
        self::assertSame('SYNCING_OUT', $this->devices->activeSession($device)['status']);
        self::assertNotSame('AVAILABLE', $this->devices->device($this->scope, $device)['status']);
        $confirmation = $this->service->agentSync($device, ['version' => 0, 'entries' => []]);
        self::assertTrue($confirmation['confirmed']);
        self::assertSame($confirmation, $this->service->agentSync($device, ['version' => $confirmation['version'], 'entries' => []]));
        $finished = ['sessionEnded' => true, 'storageConfirmed' => true];
        foreach (['STORAGE_CONFIRMED', 'CLEANUP', 'CLOSED'] as $status) {
            $evidence = $finished + ['cleanupDone' => $status === 'CLOSED'];
            $result = $this->devices->transitionSession($device, $session['id'], $status, $evidence);
            self::assertSame($status, $result['status']);
            self::assertSame($result, $this->devices->transitionSession($device, $session['id'], $status, $evidence));
        }
        self::assertNull($this->devices->activeSession($device));
        self::assertSame(1, (int) self::$db->createCommand("SELECT count(*) FROM device_management.device_events
            WHERE device_id=CAST(:device AS uuid) AND kind='session_state' AND payload->>'status'='CLOSED'", [':device' => $device])->queryScalar());
    }

    public function testProductionStoreRejectsSyntheticEvidenceAndIsolatedStoreDoesNotClaimWindowsLogon(): void
    {
        [$device] = $this->enrolledDevice();
        $this->ready($device);
        $session = $this->start($device)['id'];
        $production = new PostgresDeviceStore(self::$db);
        $production->transitionSession($device, $session, 'PREPARING');
        $production->transitionSession($device, $session, 'WINDOWS_PROFILE_READY', ['profilePrepared' => true]);
        $production->transitionSession($device, $session, 'SYNCING_IN');
        $synthetic = ['profilePrepared' => true, 'testAdapter' => true];
        $this->deviceRejects('test_adapter_not_allowed', fn() => $production->transitionSession($device, $session, 'ACTIVE', $synthetic));
        $this->deviceRejects('invalid_evidence', fn() => $this->devices->transitionSession(
            $device,
            $session,
            'ACTIVE',
            $synthetic + ['windowsLogonConfirmed' => true],
        ));
        self::assertSame('SYNCING_IN', $production->activeSession($device)['status']);
        self::assertSame('ACTIVE', $this->devices->transitionSession($device, $session, 'ACTIVE', $synthetic)['status']);
        $evidence = json_decode((string) self::$db->createCommand('SELECT evidence::text FROM device_management.device_sessions
            WHERE id=CAST(:session AS uuid)', [':session' => $session])->queryScalar(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($evidence['testAdapter']);
        self::assertNotSame(true, $evidence['windowsLogonConfirmed'] ?? null);
    }

    public function testSyncInCheckpointCannotAuthorizeCleanupEvenWhenTheSnapshotIsUnchanged(): void
    {
        [$device] = $this->enrolledDevice();
        $this->ready($device);
        $session = $this->start($device)['id'];
        $syncIn = null;
        $this->activateAndEndForSync($device, $session, function () use ($device, &$syncIn): void {
            $syncIn = $this->service->agentSync($device, ['version' => 0, 'entries' => []]);
        });
        self::assertTrue($syncIn['confirmed']);
        $evidence = ['sessionEnded' => true, 'storageConfirmed' => true];
        $this->deviceRejects('storage_not_confirmed', fn() => $this->devices->transitionSession($device, $session, 'STORAGE_CONFIRMED', $evidence));
        $syncOut = $this->service->agentSync($device, ['version' => $syncIn['version'], 'entries' => []]);
        self::assertTrue($syncOut['confirmed']);
        self::assertGreaterThan($syncIn['version'], $syncOut['version']);
        self::assertSame('STORAGE_CONFIRMED', $this->devices->transitionSession($device, $session, 'STORAGE_CONFIRMED', $evidence)['status']);
    }

    public function testRepeatedEndAndCommandAcknowledgmentDoNotCreateSessions(): void
    {
        [$device] = $this->enrolledDevice();
        $this->ready($device);
        $session = $this->start($device);
        $this->devices->transitionSession($device, $session['id'], 'PREPARING');
        $this->devices->transitionSession($device, $session['id'], 'WINDOWS_PROFILE_READY', ['profilePrepared' => true]);
        $this->devices->transitionSession($device, $session['id'], 'SYNCING_IN');
        $this->devices->transitionSession($device, $session['id'], 'ACTIVE', ['profilePrepared' => true, 'testAdapter' => true]);
        $this->devices->requestSessionEnd($this->scope, $device, $session['id']);
        $this->devices->requestSessionEnd($this->scope, $device, $session['id']);
        self::assertSame(1, (int) self::$db->createCommand("SELECT count(*) FROM device_management.device_commands WHERE device_id=CAST(:id AS uuid) AND kind='end_session'", [':id' => $device])->queryScalar());
        $command = $this->devices->pendingCommands($device)[0];
        $this->devices->acknowledgeCommand($device, $command['id']);
        self::assertTrue($this->devices->acknowledgeCommand($device, $command['id'])['acknowledged']);
        self::assertSame($session['id'], $this->devices->activeSession($device)['id']);
    }

    public function testRecoveryCanEndPreparedSessionWithoutFalselyConfirmingWindowsLogon(): void
    {
        [$device] = $this->enrolledDevice();
        $this->ready($device);
        $session = $this->start($device);
        $this->devices->transitionSession($device, $session['id'], 'PREPARING');
        $this->devices->transitionSession($device, $session['id'], 'WINDOWS_PROFILE_READY', ['profilePrepared' => true]);
        $this->devices->transitionSession($device, $session['id'], 'SYNCING_IN');
        $this->deviceRejects('end_not_requested', fn() => $this->devices->transitionSession($device, $session['id'], 'ENDING'));
        self::assertSame('SYNCING_IN', $this->devices->activeSession($device)['status']);

        $this->devices->transitionSession($device, $session['id'], 'SYNC_PENDING');
        $this->deviceRejects('end_not_requested', fn() => $this->devices->transitionSession(
            $device,
            $session['id'],
            'SYNCING_OUT',
            ['sessionEnded' => true],
        ));
        $retry = $this->devices->retryRecovery($this->scope, $device, null);
        self::assertTrue($retry['retrying']);
        self::assertSame($session['id'], $retry['sessionId']);
        self::assertSame('ENDING', $this->devices->activeSession($device)['desiredStatus']);
        $this->devices->transitionSession($device, $session['id'], 'SYNCING_IN');
        $ended = $this->devices->transitionSession($device, $session['id'], 'ENDING');
        self::assertSame('ENDING', $ended['status']);

        self::assertSame('object', self::$db->createCommand('SELECT jsonb_typeof(evidence) FROM device_management.device_sessions WHERE id=CAST(:session AS uuid)', [':session' => $session['id']])->queryScalar());
        $evidence = json_decode((string) self::$db->createCommand('SELECT evidence::text FROM device_management.device_sessions WHERE id=CAST(:session AS uuid)', [':session' => $session['id']])->queryScalar(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($evidence['profilePrepared']);
        self::assertNotSame(true, $evidence['windowsLogonConfirmed'] ?? null);
        self::assertSame(0, (int) self::$db->createCommand("SELECT count(*) FROM device_management.device_events WHERE device_id=CAST(:device AS uuid) AND kind='session_state' AND payload->>'status'='ACTIVE'", [':device' => $device])->queryScalar());
        $this->deviceRejects('session_not_ended', fn() => $this->devices->transitionSession($device, $session['id'], 'SYNCING_OUT'));
        self::assertSame('ENDING', $this->devices->activeSession($device)['status']);
        self::assertSame('SYNCING_OUT', $this->devices->transitionSession($device, $session['id'], 'SYNCING_OUT', ['sessionEnded' => true])['status']);
    }

    public function testRestoredWebVersionSurvivesStaleUploadAndNextAgentManifest(): void
    {
        $first = $this->upload('work.txt', 'first version');
        $second = $this->upload('work.txt', 'second version');
        $staleTicket = $this->service->write('ticket', $this->storageScope, ['purpose' => 'upload', 'path' => 'work.txt']);
        $restored = $this->service->write('restore_file', $this->storageScope, ['path' => 'work.txt', 'version' => 1]);
        self::assertSame(3, $restored['version']);
        $this->storageRejects('version_conflict', fn() => $this->service->uploadWithTicket($staleTicket['token'], $this->stream('stale device work'), hash('sha256', 'stale device work')));
        self::assertSame(hash('sha256', 'first version'), $this->storage->confirmedManifest($this->storageScope)['entries'][0]['sha256']);
        $device = $this->id();
        $manifest = $this->storage->mergeManifest($this->storageScope, 1, [[
            'path' => 'work.txt', 'op' => 'update', 'sha256' => hash('sha256', 'stale device work'), 'size' => strlen('stale device work'), 'baseSha256' => $second['sha256'],
        ]], $device);
        self::assertCount(1, $manifest['conflicts']);
        $this->storageRejects('version_conflict', fn() => $this->storage->confirmManifest($this->storageScope, 1, [['path' => 'work.txt', 'sha256' => hash('sha256', 'stale device work'), 'size' => strlen('stale device work')]], $device));
        self::assertCount(3, $this->storage->fileVersions($this->storageScope, 'work.txt'));
        self::assertSame($first['sha256'], $this->storage->fileByPath($this->storageScope, 'work.txt')['sha256']);
    }

    public function testSoftDeleteCannotBeReversedByStaleDeviceManifest(): void
    {
        $uploaded = $this->upload('work.txt', 'student work');
        $this->service->write('delete_file', $this->storageScope, ['path' => 'work.txt']);
        self::assertSame([], $this->storage->confirmedManifest($this->storageScope)['entries']);
        $manifest = $this->storage->mergeManifest($this->storageScope, 1, [[
            'path' => 'work.txt', 'op' => 'update', 'sha256' => hash('sha256', 'stale work'), 'size' => strlen('stale work'), 'baseSha256' => $uploaded['sha256'],
        ]], $this->id());
        self::assertCount(1, $manifest['conflicts']);
        self::assertNull($this->storage->fileByPath($this->storageScope, 'work.txt'));
        self::assertCount(1, $this->storage->fileVersions($this->storageScope, 'work.txt'), 'Deletion must preserve version history.');
    }

    public function testEmptyManifestConfirmationIsIdempotentAndOtherDeviceCannotReuseVersion(): void
    {
        [$device] = $this->enrolledDevice();
        $this->ready($device);
        $this->start($device);
        $manifest = $this->storage->mergeManifest($this->storageScope, 1, [], $device);
        self::assertSame(1, $manifest['version']);
        self::assertSame([], $manifest['uploads']);
        $confirmed = $this->storage->confirmManifest($this->storageScope, 1, [], $device);
        self::assertTrue($confirmed['confirmed']);
        self::assertSame($confirmed, $this->storage->confirmManifest($this->storageScope, 1, [], $device));
        $this->storageRejects('manifest_version_conflict', fn() => $this->storage->mergeManifest($this->storageScope, 1, [], $this->id()));
    }

    public function testPrivacyExportIncludesMoreThanOneThousandFilesAndVerifiedBinaryContent(): void
    {
        $content = "private student work\0" . str_repeat('binary', 900);
        $this->upload('binary.txt', $content);
        $space = $this->storage->space($this->storageScope);
        self::$db->createCommand("INSERT INTO storage.files(space_id,path)
            SELECT CAST(:space AS uuid),'folder/export-' || n || '.txt' FROM generate_series(1,1005) AS n", [':space' => $space['id']])->execute();
        $contributor = new \App\Storage\Infrastructure\PersonalDataContributor(self::$db, $this->files);
        $context = new \App\Core\Privacy\Application\PersonalDataExportContext($this->scope->personId, $this->id(), $this->scope->personId, $this->scope->accountId, null, new \DateTimeImmutable('2026-11-11T00:00:00Z'));
        $export = (new \App\Core\Privacy\Application\PersonalDataExporter(new \App\Core\Privacy\Application\PersonalDataContributorRegistry([$contributor])))->export($context);
        $records = [];
        foreach ($export->sections as $section) {
            self::assertLessThanOrEqual(1000, count($section->records));
            array_push($records, ...$section->records);
        }
        self::assertCount(1006, array_filter($records, static fn(array $record): bool => $record['type'] === 'file'));
        $chunks = array_values(array_filter($records, static fn(array $record): bool => $record['type'] === 'content_chunk'));
        self::assertGreaterThan(1, count($chunks));
        $decoded = '';
        foreach ($chunks as $chunk) {
            $decoded .= base64_decode($chunk['content'], true);
        }
        self::assertSame($content, $decoded);
        self::assertStringNotContainsString('storageKey', $export->json);
    }

    public function testLargePrivacyArtifactStreamsVerifiedBinaryAndRejectsLaterCorruption(): void
    {
        $content = str_repeat("large work\0\xff", 180000);
        $this->upload('large.bin', $content);
        $contributor = new \App\Storage\Infrastructure\PersonalDataContributor(self::$db, $this->files);
        $context = new \App\Core\Privacy\Application\PersonalDataExportContext($this->scope->personId, $this->id(), $this->scope->personId, $this->scope->accountId, null, new \DateTimeImmutable('2026-11-13T00:00:00Z'));
        $exporter = new \App\Core\Privacy\Application\PersonalDataExporter(new \App\Core\Privacy\Application\PersonalDataContributorRegistry([$contributor]));
        $artifact = $exporter->exportToStream($context);
        $stream = $artifact->detachStream();
        try {
            $json = stream_get_contents($stream);
            self::assertGreaterThan(1048576, $artifact->sizeBytes);
            self::assertSame(strlen($json), $artifact->sizeBytes);
            self::assertSame(hash('sha256', $json), $artifact->sha256);
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            $decoded = '';
            $expectedIndex = 0;
            foreach ($document['sections'] as $section) {
                foreach ($section['records'] as $record) {
                    if ($record['type'] !== 'content_chunk') {
                        continue;
                    }
                    self::assertSame($expectedIndex++, $record['chunk']);
                    $decoded .= base64_decode($record['content'], true);
                }
            }
            self::assertSame($content, $decoded);
            self::assertStringNotContainsString('storageKey', $json);
        } finally {
            fclose($stream);
        }
        // Simulate corruption of the test S3 object; no partial artifact may escape after checksum failure.
        $objects = $this->files->keys();
        self::assertCount(1, $objects);
        $corrupt = tmpfile();
        fwrite($corrupt, 'corrupted test object');
        rewind($corrupt);
        $this->files->write($objects[0], $corrupt);
        fclose($corrupt);
        $before = count(get_resources('stream'));
        try {
            $exporter->exportToStream($context);
            self::fail('A corrupt binary was exported.');
        } catch (\DomainException $error) {
            self::assertSame('Storage export content failed checksum verification.', $error->getMessage());
            self::assertSame($before, count(get_resources('stream')));
        }
    }

    public function testConflictRebaseRejectsUnresolvedChoicesAndAnotherDevice(): void
    {
        $case = $this->conflictedSyncOut(authorizeRecovery: false);
        self::assertNull($case['manifest']['conflicts'][0]['resolution']);
        self::assertFalse($case['manifest']['conflicts'][0]['serverDeleted']);
        $this->storageRejects('recovery_not_authorized', fn() => $this->service->agentRebaseManifest($case['device'], ['version' => 1]));
        $this->authorizeRecoveryForSync($case['device'], $case['session']);
        // A choice from a different manifest must not resolve the current one.
        $space = $this->storage->space($this->storageScope);
        self::$db->createCommand("INSERT INTO storage.conflicts(space_id,path,resolution,resolved_at)
            VALUES(CAST(:space AS uuid),'work.txt','keep_server',now())", [':space' => $space['id']])->execute();
        $this->storageRejects('unresolved_conflicts', fn() => $this->service->agentRebaseManifest($case['device'], ['version' => 1]));
        self::assertSame('pending', $this->manifestStatus($case['manifest']['manifestId']));

        [$otherDevice] = $this->enrolledDevice();
        $this->ready($otherDevice);
        $otherSession = $this->start($otherDevice);
        $this->activateAndEndForSync($otherDevice, $otherSession['id']);
        $this->authorizeRecoveryForSync($otherDevice, $otherSession['id']);
        $this->storageRejects('not_found', fn() => $this->service->agentRebaseManifest($otherDevice, ['version' => 1]));
        self::assertSame('pending', $this->manifestStatus($case['manifest']['manifestId']));
        self::assertSame($case['serverSha'], $this->storage->fileByPath($this->storageScope, 'work.txt')['sha256']);
    }

    public function testResolvedRebaseExpiresOldTicketsAndKeepsUniqueDeviceBytesBeforeCleanup(): void
    {
        $case = $this->conflictedSyncOut();
        $conflictId = (string) self::$db->createCommand('SELECT id::text FROM storage.conflicts WHERE manifest_id=CAST(:manifest AS uuid) AND path=\'work.txt\'', [':manifest' => $case['manifest']['manifestId']])->queryScalar();
        $choice = $this->service->write('resolve_conflict', $this->storageScope, ['resolution' => 'keep_server'], $conflictId);
        self::assertSame('keep_server', $choice['resolution']);
        $latest = $this->service->agentManifest($case['device'], ['version' => 1, 'entries' => $case['entries']]);
        self::assertSame('keep_server', $latest['conflicts'][0]['resolution']);
        $oldTicket = $latest['uploads'][0];
        self::assertNotNull($this->storage->findTicket(hash('sha256', $oldTicket['token'])));

        $rebased = $this->service->agentRebaseManifest($case['device'], ['version' => 1]);
        self::assertTrue($rebased['rebased']);
        self::assertSame(2, $rebased['nextVersion']);
        self::assertSame('accepted', $this->manifestStatus($case['manifest']['manifestId']));
        self::assertSame($rebased, $this->service->agentRebaseManifest($case['device'], ['version' => 1]));
        $replayed = $this->service->agentManifest($case['device'], ['version' => 1, 'entries' => $case['entries']]);
        self::assertSame([], $replayed['uploads'], 'An archived manifest must not issue fresh upload credentials.');
        $ticket = $this->storage->findTicket(hash('sha256', $oldTicket['token']));
        self::assertLessThanOrEqual(new \DateTimeImmutable('now'), new \DateTimeImmutable($ticket['expiresAt']));
        $this->storageRejects('not_found', fn() => $this->service->uploadWithTicket($oldTicket['token'], $this->stream($case['pendingBytes']), hash('sha256', $case['pendingBytes']), $case['device']));
        self::assertNull($this->storage->fileByPath($this->storageScope, 'pending.txt'));
        $this->storageRejects('manifest_not_submitted', fn() => $this->service->agentSync($case['device'], [
            'version' => 1, 'entries' => [['path' => 'work.txt', 'sha256' => $case['serverSha'], 'size' => strlen($case['serverBytes'])]],
        ]));
        $this->deviceRejects('storage_not_confirmed', fn() => $this->devices->transitionSession($case['device'], $case['session'], 'STORAGE_CONFIRMED', ['sessionEnded' => true, 'storageConfirmed' => true]));

        $copyPath = 'Conflicts/' . $case['session'] . '/' . hash('sha256', $case['localBytes']) . '.bin';
        $content = [$copyPath => $case['localBytes'], 'pending.txt' => $case['pendingBytes']];
        $entries = [];
        foreach ($content as $path => $bytes) {
            $entries[] = ['path' => $path, 'op' => 'add', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        }
        $replacement = $this->service->agentManifest($case['device'], ['version' => $rebased['nextVersion'], 'entries' => $entries]);
        self::assertSame([], $replacement['conflicts']);
        self::assertCount(2, $replacement['uploads']);
        foreach ($replacement['uploads'] as $upload) {
            $bytes = $content[$upload['path']];
            $this->service->uploadWithTicket($upload['token'], $this->stream($bytes), hash('sha256', $bytes), $case['device']);
        }
        $snapshot = [['path' => 'work.txt', 'sha256' => $case['serverSha'], 'size' => strlen($case['serverBytes'])]];
        foreach ($content as $path => $bytes) {
            $snapshot[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        }
        $confirmed = $this->service->agentSync($case['device'], ['version' => 2, 'entries' => $snapshot]);
        self::assertTrue($confirmed['confirmed']);
        self::assertSame(3, $confirmed['verifiedEntries']);
        self::assertSame($confirmed, $this->service->agentSync($case['device'], ['version' => 2, 'entries' => $snapshot]));
        $this->storageRejects('manifest_not_submitted', fn() => $this->service->agentSync($case['device'], ['version' => 1, 'entries' => $snapshot]));
        self::assertSame(2, $this->storage->confirmedManifest($this->storageScope)['version'], 'Archived confirmation must not create another checkpoint.');
        self::assertSame($case['serverSha'], $this->storage->fileByPath($this->storageScope, 'work.txt')['sha256']);
        self::assertCount(2, $this->storage->fileVersions($this->storageScope, 'work.txt'), 'Rebase must preserve the browser-selected original version.');
        $savedCopy = $this->storage->fileByPath($this->storageScope, $copyPath);
        self::assertSame(hash('sha256', $case['localBytes']), $savedCopy['sha256']);
        $files = $this->files;
        self::assertSame($case['localBytes'], stream_get_contents($files->open($savedCopy['storageKey'])));
        foreach (['STORAGE_CONFIRMED', 'CLEANUP'] as $status) {
            $this->devices->transitionSession($case['device'], $case['session'], $status, ['sessionEnded' => true, 'storageConfirmed' => true]);
        }
        $this->devices->transitionSession($case['device'], $case['session'], 'CLOSED', ['sessionEnded' => true, 'storageConfirmed' => true, 'cleanupDone' => true]);
        self::assertNull($this->devices->activeSession($case['device']));
        self::assertSame('AVAILABLE', $this->devices->device($this->scope, $case['device'])['status']);
    }

    public function testConflictResponseMarksServerDeletionAndResolvedRebaseCannotReviveIt(): void
    {
        $case = $this->conflictedSyncOut(deleted: true);
        self::assertTrue($case['manifest']['conflicts'][0]['serverDeleted']);
        self::assertNull($this->storage->fileByPath($this->storageScope, 'work.txt'));
        $conflictId = (string) self::$db->createCommand('SELECT id::text FROM storage.conflicts WHERE manifest_id=CAST(:manifest AS uuid) AND path=\'work.txt\'', [':manifest' => $case['manifest']['manifestId']])->queryScalar();
        $this->service->write('resolve_conflict', $this->storageScope, ['resolution' => 'keep_server'], $conflictId);
        $response = $this->service->agentManifest($case['device'], ['version' => 1, 'entries' => $case['entries']]);
        self::assertTrue($response['conflicts'][0]['serverDeleted']);
        self::assertSame('keep_server', $response['conflicts'][0]['resolution']);
        self::assertTrue($this->service->agentRebaseManifest($case['device'], ['version' => 1])['rebased']);
        self::assertSame([], $this->storage->confirmedManifest($this->storageScope)['entries']);
        self::assertCount(2, $this->storage->fileVersions($this->storageScope, 'work.txt'));
    }

    public function testRebaseArchiveInvalidatesEarlierSyncInConfirmationAsCleanupProof(): void
    {
        $case = $this->conflictedSyncOut(priorSyncInProof: true);
        self::assertSame(2, $case['manifestVersion']);
        $conflictId = (string) self::$db->createCommand('SELECT id::text FROM storage.conflicts WHERE manifest_id=CAST(:manifest AS uuid) AND path=\'work.txt\'', [':manifest' => $case['manifest']['manifestId']])->queryScalar();
        $this->service->write('resolve_conflict', $this->storageScope, ['resolution' => 'keep_server'], $conflictId);
        $rebased = $this->service->agentRebaseManifest($case['device'], ['version' => 2]);
        self::assertSame(3, $rebased['nextVersion']);
        self::assertSame('accepted', $this->manifestStatus($case['manifest']['manifestId']));
        $space = $this->storage->space($this->storageScope);
        self::assertSame(0, (int) self::$db->createCommand("SELECT count(*) FROM storage.manifests WHERE space_id=CAST(:space AS uuid) AND status='pending'", [':space' => $space['id']])->queryScalar());
        self::assertSame(1, (int) self::$db->createCommand("SELECT count(*) FROM storage.manifests m JOIN device_management.device_sessions s ON s.device_id=m.device_id
            WHERE m.space_id=CAST(:space AS uuid) AND m.version=1 AND m.status='confirmed' AND s.id=CAST(:session AS uuid) AND m.confirmed_at>=s.started_at", [':space' => $space['id'], ':session' => $case['session']])->queryScalar());
        $this->deviceRejects('storage_not_confirmed', fn() => $this->devices->transitionSession($case['device'], $case['session'], 'STORAGE_CONFIRMED', ['sessionEnded' => true, 'storageConfirmed' => true]));
        self::assertSame('SYNCING_OUT', $this->devices->activeSession($case['device'])['status']);
        self::assertNotSame('AVAILABLE', $this->devices->device($this->scope, $case['device'])['status']);
    }

    public function testPrivacyErasureCannotDeleteOnlyCopyDuringPendingRecovery(): void
    {
        $this->upload('unsaved.txt', 'only retained version');
        $space = $this->storage->space($this->storageScope);
        [$device] = $this->enrolledDevice();
        $this->ready($device);
        $session = $this->start($device);
        $this->devices->transitionSession($device, $session['id'], 'RECOVERY_REQUIRED');
        $recovery = new \App\Storage\Infrastructure\RecoveryDependencyContributor(self::$db);
        self::assertTrue($recovery->hasPendingRecovery($this->scope->personId));
        $handler = new \App\Storage\Infrastructure\SpaceErasureHandler(self::$db, $recovery);
        try {
            $handler->execute($this->scope->personId, $space['id'], 'delete', $this->id());
            self::fail('Erasure destroyed documents during unresolved recovery.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('recovery', $error->getMessage());
        }
        self::assertNotNull($this->storage->fileByPath($this->storageScope, 'unsaved.txt'));
        self::assertCount(1, $this->files->keys() ?: []);
        self::assertSame(0, (int) self::$db->createCommand('SELECT count(*) FROM storage.binary_cleanup')->queryScalar());
    }

    public function testCommittedErasureDeletesBinaryThroughIdempotentOutbox(): void
    {
        $this->upload('erased.txt', 'authorized erasure content');
        $space = $this->storage->space($this->storageScope);
        $item = $this->id();
        $handler = new \App\Storage\Infrastructure\SpaceErasureHandler(self::$db, new \App\Storage\Infrastructure\RecoveryDependencyContributor(self::$db));
        self::assertSame('deleted', $handler->execute($this->scope->personId, $space['id'], 'delete', $item));
        self::assertSame('deleted', $handler->execute($this->scope->personId, $space['id'], 'delete', $item));
        self::assertCount(1, $this->files->keys() ?: [], 'Binary deletion must wait for committed erasure metadata.');
        $cleanup = new \App\Storage\Infrastructure\BinaryCleanup(self::$db, $this->files);
        try {
            $cleanup->drain();
            self::fail('Uncommitted erasure binaries were deleted.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('committed', $error->getMessage());
        }
        $this->transaction->commit();
        try {
            self::assertSame(1, $cleanup->drain());
            self::assertSame(0, $cleanup->drain(), 'Repeated cleanup must be idempotent.');
            self::assertSame([], $this->files->keys());
            self::assertSame('deleted', $handler->reconcile($item));
        } finally {
            self::$db->createCommand('DELETE FROM storage.binary_cleanup WHERE completed_at IS NOT NULL')->execute();
            self::$db->createCommand('DELETE FROM storage.privacy_receipts WHERE item_id=CAST(:item AS uuid)', [':item' => $item])->execute();
            self::$db->createCommand('DELETE FROM education.students WHERE id=CAST(:student AS uuid)', [':student' => $this->student])->execute();
            self::$db->createCommand('DELETE FROM core.persons WHERE id=CAST(:person AS uuid)', [':person' => $this->scope->personId])->execute();
        }
    }

    /** @return array{string,string} */
    private function enrolledDevice(): array
    {
        $classroom = $this->devices->createClassroom($this->scope, 'Regression classroom');
        $device = $this->devices->createDevice($this->scope, $classroom['id'], 'Regression PC', '1');
        $enrollment = $this->devices->createEnrollment($this->scope, $device['id'], hash('sha256', random_bytes(12)), '2099-01-01T00:00:00Z');
        $this->devices->confirmEnrollment($enrollment['id'], $device['id'], hash('sha256', random_bytes(12)));
        return [$device['id'], $classroom['id']];
    }

    private function ready(string $device): void
    {
        $this->devices->heartbeat($device, json_encode(['machineReady' => true, 'hasUnfinishedDocuments' => false], JSON_THROW_ON_ERROR));
    }

    private function start(string $device): array
    {
        return $this->devices->startSession($this->scope, $device, $this->student, ['personId' => $this->scope->personId, 'name' => 'Student Regression']);
    }

    private function upload(string $path, string $content): array
    {
        $ticket = $this->service->write('ticket', $this->storageScope, ['purpose' => 'upload', 'path' => $path]);
        return $this->service->uploadWithTicket($ticket['token'], $this->stream($content), hash('sha256', $content));
    }

    private function activateAndEndForSync(string $device, string $session, ?callable $afterSyncIn = null): void
    {
        $this->devices->transitionSession($device, $session, 'PREPARING');
        $this->devices->transitionSession($device, $session, 'WINDOWS_PROFILE_READY', ['profilePrepared' => true]);
        $this->devices->transitionSession($device, $session, 'SYNCING_IN');
        if ($afterSyncIn !== null) {
            $afterSyncIn();
        }
        $this->devices->transitionSession($device, $session, 'ACTIVE', ['profilePrepared' => true, 'testAdapter' => true]);
        $this->devices->requestSessionEnd($this->scope, $device, $session);
        $this->devices->transitionSession($device, $session, 'ENDING');
        $this->devices->transitionSession($device, $session, 'SYNCING_OUT', ['sessionEnded' => true]);
    }

    private function authorizeRecoveryForSync(string $device, string $session): void
    {
        $this->devices->transitionSession($device, $session, 'SYNC_PENDING');
        self::assertTrue($this->devices->retryRecovery($this->scope, $device, null)['retrying']);
        $this->devices->transitionSession($device, $session, 'SYNCING_OUT', ['sessionEnded' => true]);
    }

    private function conflictedSyncOut(bool $deleted = false, bool $authorizeRecovery = true, bool $priorSyncInProof = false): array
    {
        $old = $this->upload('work.txt', 'old server version');
        $serverBytes = 'newer browser-selected server version';
        $current = $this->upload('work.txt', $serverBytes);
        if ($deleted) {
            $this->service->write('delete_file', $this->storageScope, ['path' => 'work.txt']);
        }
        [$device] = $this->enrolledDevice();
        $this->ready($device);
        $session = $this->start($device)['id'];
        $manifestVersion = 1;
        $afterSyncIn = $priorSyncInProof ? function () use ($device, $current, $serverBytes, &$manifestVersion): void {
            $proof = $this->service->agentSync($device, ['version' => 0, 'entries' => [
                ['path' => 'work.txt', 'sha256' => $current['sha256'], 'size' => strlen($serverBytes)],
            ]]);
            self::assertTrue($proof['confirmed']);
            $manifestVersion = $proof['version'] + 1;
        } : null;
        $this->activateAndEndForSync($device, $session, $afterSyncIn);
        $localBytes = 'unique unsaved device document';
        $pendingBytes = 'another unsaved device file';
        $entries = [
            ['path' => 'work.txt', 'op' => 'update', 'sha256' => hash('sha256', $localBytes), 'size' => strlen($localBytes), 'baseSha256' => $old['sha256']],
            ['path' => 'pending.txt', 'op' => 'add', 'sha256' => hash('sha256', $pendingBytes), 'size' => strlen($pendingBytes)],
        ];
        $manifest = $this->service->agentManifest($device, ['version' => $manifestVersion, 'entries' => $entries]);
        self::assertCount(1, $manifest['conflicts']);
        self::assertCount(1, $manifest['uploads']);
        if ($authorizeRecovery) {
            $this->authorizeRecoveryForSync($device, $session);
        }
        return ['device' => $device, 'session' => $session, 'entries' => $entries, 'manifest' => $manifest,
            'manifestVersion' => $manifestVersion, 'serverBytes' => $serverBytes, 'serverSha' => $current['sha256'], 'localBytes' => $localBytes, 'pendingBytes' => $pendingBytes];
    }

    private function manifestStatus(string $id): string
    {
        return (string) self::$db->createCommand('SELECT status FROM storage.manifests WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryScalar();
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }

    private function id(): string
    {
        return (string) self::$db->createCommand('SELECT uuidv7()::text')->queryScalar();
    }
    private function deviceRejects(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected device error: ' . $reason);
        } catch (DeviceError $error) {
            self::assertSame($reason, $error->reason);
        }
    }
    private function storageRejects(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected storage error: ' . $reason);
        } catch (StorageError $error) {
            self::assertSame($reason, $error->reason);
        }
    }
}
