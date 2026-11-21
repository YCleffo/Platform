<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\DeviceManagement\Application\DeviceStore;
use App\Storage\Application\Mutation;
use App\Storage\Application\StorageError;
use App\Storage\Application\StorageScope;
use App\Storage\Application\StorageService;
use App\Storage\Application\StorageStore;
use App\Tests\Storage02\MemoryObjects;

require_once dirname(__DIR__, 2) . '/Storage02/MemoryObjects.php';
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real file bytes and application operations; database behavior has separate integration tests. */
final class ClassroomStorageRegressionTest extends TestCase
{
    private const DEVICE = '01961be3-2d44-7000-8000-000000000001';
    private const OTHER_DEVICE = '01961be3-2d44-7000-8000-000000000002';
    private const SPACE = '01961be3-2d44-7000-8000-000000000003';
    private const STUDENT = '01961be3-2d44-7000-8000-000000000004';
    private const ORGANIZATION = '01961be3-2d44-7000-8000-000000000005';
    private const TICKET = '01961be3-2d44-7000-8000-000000000006';
    private const BINARY = '01961be3-2d44-7000-8000-000000000007';

    private StorageStore&MockObject $store;
    private DeviceStore&MockObject $devices;
    private MemoryObjects $files;
    private StorageService $service;
    private StorageScope $scope;

    protected function setUp(): void
    {
        $this->files = new MemoryObjects();
        $this->store = $this->createMock(StorageStore::class);
        $this->devices = $this->createMock(DeviceStore::class);
        $this->service = new StorageService($this->store, $this->files, new class implements Mutation {
            public function run(StorageScope $scope, string $operation, callable $action): array
            {
                return $action();
            }
        }, $this->devices);
        $this->scope = new StorageScope(self::ORGANIZATION, '', '', 'en-US', 'UTC', self::STUDENT);
        $this->store->method('spaceById')->with(self::SPACE)->willReturn([
            'organizationId' => self::ORGANIZATION, 'studentId' => self::STUDENT,
        ]);
    }

    public function testIncorrectContentChecksumCannotCommitOrLeaveBinary(): void
    {
        $this->ticket();
        $this->store->expects(self::never())->method('commitUpload');
        $this->rejects('invalid_input', fn() => $this->service->uploadWithTicket('ticket-secret', $this->stream('student work'), str_repeat('0', 64)));
        self::assertSame([], $this->files->keys());
    }

    public function testExpectedManifestChecksumCannotBeBypassedByCorrectContentHeader(): void
    {
        $this->ticket(['expectedSha256' => hash('sha256', 'expected work')]);
        $this->store->expects(self::never())->method('commitUpload');
        $content = 'different work';
        $this->rejects('checksum_mismatch', fn() => $this->service->uploadWithTicket('ticket-secret', $this->stream($content), hash('sha256', $content)));
        self::assertSame([], $this->files->keys());
    }

    public function testDatabaseFailureCompensatesNewUploadBinary(): void
    {
        $this->ticket();
        $this->store->expects(self::once())->method('commitUpload')->willReturnCallback(
            function (string $ticket, StorageScope $scope, string $path, int $size, string $sha, string $key): never {
                self::assertSame(self::TICKET, $ticket);
                self::assertSame('nested/work.txt', $path);
                self::assertSame('student work', stream_get_contents($this->files->open($key)));
                throw new RuntimeException('simulated SQL rollback');
            },
        );
        try {
            $this->service->uploadWithTicket('ticket-secret', $this->stream('student work'), hash('sha256', 'student work'));
            self::fail('SQL failure must be returned.');
        } catch (RuntimeException $failure) {
            self::assertSame('simulated SQL rollback', $failure->getMessage());
        }
        self::assertSame([], $this->files->keys(), 'Rolled back uploads must not leave a binary object.');
    }

    public function testRestoreCopiesHistoricalBytesAndCompensatesFailedSql(): void
    {
        $content = "historical work\0binary";
        $this->files->write(self::BINARY, $this->stream($content));
        $this->store->method('fileByPath')->willReturn(['id' => self::BINARY]);
        $this->store->method('versionByNumber')->willReturn([
            'storageKey' => self::BINARY, 'sha256' => hash('sha256', $content), 'sizeBytes' => strlen($content),
        ]);
        $this->store->expects(self::once())->method('restoreVersion')->willReturnCallback(
            function (StorageScope $scope, string $path, int $version, string $key) use ($content): never {
                self::assertSame('nested/work.txt', $path);
                self::assertSame(1, $version);
                self::assertNotSame(self::BINARY, $key);
                self::assertSame($content, stream_get_contents($this->files->open($key)));
                throw new RuntimeException('simulated restore rollback');
            },
        );
        try {
            $this->service->write('restore_file', $this->scope, ['path' => 'nested/work.txt', 'version' => 1]);
            self::fail('Restore SQL failure must be returned.');
        } catch (RuntimeException $failure) {
            self::assertSame('simulated restore rollback', $failure->getMessage());
        }
        self::assertSame([self::BINARY], $this->files->keys());
        self::assertSame($content, stream_get_contents($this->files->open(self::BINARY)), 'Historical bytes survive compensation.');
    }

    public function testDeviceBoundTicketCannotUploadFromOtherDevice(): void
    {
        $this->ticket(['deviceId' => self::DEVICE]);
        $this->devices->method('activeSession')->with(self::OTHER_DEVICE)->willReturn([
            'organizationId' => self::ORGANIZATION, 'studentId' => self::STUDENT,
        ]);
        $this->store->expects(self::never())->method('commitUpload');
        $this->rejects('not_found', fn() => $this->service->uploadWithTicket('ticket-secret', $this->stream('work'), hash('sha256', 'work'), self::OTHER_DEVICE));
        self::assertSame([], $this->files->keys());
    }

    public function testEmptySyncSnapshotStillRequiresStoreConfirmation(): void
    {
        $this->devices->method('activeSession')->with(self::DEVICE)->willReturn([
            'organizationId' => self::ORGANIZATION, 'studentId' => self::STUDENT,
        ]);
        $this->store->expects(self::once())->method('confirmManifest')
            ->with(self::callback(static fn(StorageScope $scope): bool => $scope->studentId === self::STUDENT), 8, [], self::DEVICE)
            ->willReturn(['confirmed' => true, 'version' => 8, 'verifiedEntries' => 0]);
        self::assertSame(['confirmed' => true, 'version' => 8, 'verifiedEntries' => 0], $this->service->agentSync(self::DEVICE, ['version' => 8, 'entries' => []]));
    }

    public function testUnknownDeviceCannotReadStudentManifest(): void
    {
        $this->devices->method('activeSession')->willReturn(null);
        $this->store->expects(self::never())->method('confirmedManifest');
        $this->rejects('not_found', fn() => $this->service->agentConfirmedManifest(self::OTHER_DEVICE));
    }

    public function testMalformedSnapshotHashCannotReachConfirmation(): void
    {
        $this->devices->method('activeSession')->willReturn([
            'organizationId' => self::ORGANIZATION, 'studentId' => self::STUDENT,
        ]);
        $this->store->expects(self::never())->method('confirmManifest');
        $this->rejects('invalid_input', fn() => $this->service->agentSync(self::DEVICE, [
            'version' => 9, 'entries' => [['path' => 'work.txt', 'sha256' => 'not-a-checksum', 'size' => 4]],
        ]));
    }

    /** @param array<string,mixed> $overrides */
    private function ticket(array $overrides = []): void
    {
        $this->store->method('findTicket')->with(hash('sha256', 'ticket-secret'))->willReturn([
            'id' => self::TICKET, 'spaceId' => self::SPACE, 'purpose' => 'upload', 'path' => 'nested/work.txt',
            'expiresAt' => '2099-01-01T00:00:00Z', 'usedAt' => null, 'expectedSha256' => null,
            'expectedSize' => null, 'deviceId' => null, ...$overrides,
        ]);
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

    private function rejects(string $reason, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected storage operation rejection: ' . $reason);
        } catch (StorageError $error) {
            self::assertSame($reason, $error->reason);
        }
    }
}
