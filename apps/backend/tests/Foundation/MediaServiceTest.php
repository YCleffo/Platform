<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Media\Application\MediaError;
use App\Media\Application\MediaScope;
use App\Media\Application\MediaService;
use App\Media\Domain\MediaKind;
use App\Media\Domain\MediaStatus;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Connection\ConnectionInterface;

final class MediaServiceTest extends TestCase
{
    public function testMediaKindValidationAndAllowlists(): void
    {
        self::assertTrue(MediaKind::isValid('voice'));
        self::assertTrue(MediaKind::isValid('video_note'));
        self::assertTrue(MediaKind::isValid('audio'));
        self::assertFalse(MediaKind::isValid('malware'));

        $voice = MediaKind::Voice;
        self::assertSame(50 * 1024 * 1024, $voice->maxSizeBytes());
        self::assertContains('audio/ogg', $voice->allowedMimeTypes());
        self::assertContains('audio/webm', $voice->allowedMimeTypes());

        $videoNote = MediaKind::VideoNote;
        self::assertSame(100 * 1024 * 1024, $videoNote->maxSizeBytes());
        self::assertContains('video/mp4', $videoNote->allowedMimeTypes());
    }

    public function testMediaStatusStateTransitions(): void
    {
        self::assertFalse(MediaStatus::Reserved->isTerminal());
        self::assertFalse(MediaStatus::Processing->isTerminal());
        self::assertTrue(MediaStatus::Ready->isTerminal());
        self::assertTrue(MediaStatus::Failed->isTerminal());
    }

    public function testReserveUploadRejectsInvalidChecksum(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $service = new MediaService($db);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('invalid_checksum');
        $service->reserveUpload($scope, 'voice', 'note.ogg', 'audio/ogg', 1024, 'not-a-valid-sha256');
    }

    public function testReserveUploadRejectsUnsupportedMime(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $service = new MediaService($db);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('unsupported_mime_type');
        $sha = str_repeat('a', 64);
        $service->reserveUpload($scope, 'voice', 'note.exe', 'application/x-dosexec', 1024, $sha);
    }

    public function testReserveUploadRejectsSizeExceeded(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $service = new MediaService($db);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('size_exceeded');
        $sha = str_repeat('a', 64);
        // Exceeds 50MB voice limit
        $service->reserveUpload($scope, 'voice', 'note.ogg', 'audio/ogg', 60 * 1024 * 1024, $sha);
    }

    public function testConfirmUploadRejectsExpiredOrMissingSession(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $command = $this->createMock(\Yiisoft\Db\Command\CommandInterface::class);

        $db->method('transaction')->willReturnCallback(fn($callback) => $callback());
        $db->method('createCommand')->willReturn($command);

        // First call: find asset (success)
        // Second call: find session (returns false / null)
        $command->method('queryOne')->willReturnOnConsecutiveCalls(
            [
                'id' => '00000000-0000-0000-0000-000000000010',
                'status' => 'reserved',
                'kind' => 'voice',
                'original_mime' => 'audio/ogg',
                'size_bytes' => 1024,
                'sha256' => str_repeat('a', 64),
                'storageKey' => 'voice/00000000-0000-0000-0000-000000000010.ogg',
            ],
            null, // expired or missing session
        );

        $service = new MediaService($db);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('session_expired_or_invalid');
        $service->confirmUpload($scope, '00000000-0000-0000-0000-000000000010');
    }

    public function testConfirmUploadRejectsChecksumMismatch(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $command = $this->createMock(\Yiisoft\Db\Command\CommandInterface::class);

        $db->method('transaction')->willReturnCallback(fn($callback) => $callback());
        $db->method('createCommand')->willReturn($command);

        $command->method('queryOne')->willReturnOnConsecutiveCalls(
            [
                'id' => '00000000-0000-0000-0000-000000000010',
                'status' => 'reserved',
                'kind' => 'voice',
                'original_mime' => 'audio/ogg',
                'size_bytes' => 1024,
                'sha256' => str_repeat('a', 64),
                'storageKey' => 'voice/00000000-0000-0000-0000-000000000010.ogg',
            ],
            [
                'staging_key' => 'staging/00000000-0000-0000-0000-000000000010.ogg',
                'expires_at' => '2026-11-25 00:00:00',
            ],
        );

        $service = new MediaService($db);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('checksum_mismatch');
        $service->confirmUpload($scope, '00000000-0000-0000-0000-000000000010', ['sha256' => str_repeat('b', 64)]);
    }

    public function testConfirmUploadRejectsSizeMismatch(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $command = $this->createMock(\Yiisoft\Db\Command\CommandInterface::class);

        $db->method('transaction')->willReturnCallback(fn($callback) => $callback());
        $db->method('createCommand')->willReturn($command);

        $command->method('queryOne')->willReturnOnConsecutiveCalls(
            [
                'id' => '00000000-0000-0000-0000-000000000010',
                'status' => 'reserved',
                'kind' => 'voice',
                'original_mime' => 'audio/ogg',
                'size_bytes' => 1024,
                'sha256' => str_repeat('a', 64),
                'storageKey' => 'voice/00000000-0000-0000-0000-000000000010.ogg',
            ],
            [
                'staging_key' => 'staging/00000000-0000-0000-0000-000000000010.ogg',
                'expires_at' => '2026-11-25 00:00:00',
            ],
        );

        $service = new MediaService($db);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('size_mismatch');
        $service->confirmUpload($scope, '00000000-0000-0000-0000-000000000010', ['sizeBytes' => 99999]);
    }

    public function testPlaybackUrlThrowsWhenNotReady(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $command = $this->createMock(\Yiisoft\Db\Command\CommandInterface::class);
        $db->method('createCommand')->willReturn($command);
        $command->method('queryOne')->willReturn([
            'id' => '00000000-0000-0000-0000-000000000010',
            'kind' => 'voice',
            'originalName' => 'voice.ogg',
            'originalMime' => 'audio/ogg',
            'verifiedMime' => null,
            'sizeBytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'storageKey' => '00000000-0000-0000-0000-000000000010',
            'durationSeconds' => 5.2,
            'width' => null,
            'height' => null,
            'codec' => 'opus',
            'status' => 'reserved',
            'metadata' => [],
            'createdAt' => '2026-11-24 12:00:00',
            'updatedAt' => '2026-11-24 12:00:00',
        ]);

        $service = new MediaService($db);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('media_not_ready');
        $service->playbackUrl($scope, '00000000-0000-0000-0000-000000000010');
    }

    public function testPlaybackUrlReturnsStreamUrlForReadyAsset(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $command = $this->createMock(\Yiisoft\Db\Command\CommandInterface::class);
        $db->method('createCommand')->willReturn($command);
        $command->method('queryOne')->willReturn([
            'id' => '00000000-0000-0000-0000-000000000010',
            'kind' => 'voice',
            'originalName' => 'voice.ogg',
            'originalMime' => 'audio/ogg',
            'verifiedMime' => 'audio/ogg',
            'sizeBytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'storageKey' => '00000000-0000-0000-0000-000000000010',
            'durationSeconds' => 5.2,
            'width' => null,
            'height' => null,
            'codec' => 'opus',
            'status' => 'READY',
            'metadata' => [],
            'createdAt' => '2026-11-24 12:00:00',
            'updatedAt' => '2026-11-24 12:00:00',
        ]);
        $command->method('queryAll')->willReturn([]);

        $service = new MediaService($db);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $playback = $service->playbackUrl($scope, '00000000-0000-0000-0000-000000000010');
        self::assertStringStartsWith('/api/media/stream/00000000-0000-0000-0000-000000000010', $playback['url']);
        self::assertSame('audio/ogg', $playback['mime']);
        self::assertEquals(5.2, $playback['durationSeconds']);
    }

    public function testConfirmUploadFailsClosedWhenStorageUnavailable(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $command = $this->createMock(\Yiisoft\Db\Command\CommandInterface::class);
        $db->method('transaction')->willReturnCallback(fn($callback) => $callback());
        $db->method('createCommand')->willReturn($command);

        $command->method('queryOne')->willReturnOnConsecutiveCalls(
            [
                'id' => '00000000-0000-0000-0000-000000000010',
                'status' => 'reserved',
                'kind' => 'voice',
                'original_mime' => 'audio/webm',
                'size_bytes' => 1024,
                'sha256' => str_repeat('a', 64),
                'storageKey' => '00000000-0000-0000-0000-000000000010',
            ],
            [
                'staging_key' => 'staging/00000000-0000-0000-0000-000000000010.webm',
                'expires_at' => '2026-11-26 00:00:00',
            ],
        );

        $service = new MediaService($db, null);
        $scope = new MediaScope('00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003');

        $this->expectException(MediaError::class);
        $this->expectExceptionMessage('storage_unavailable');
        $service->confirmUpload($scope, '00000000-0000-0000-0000-000000000010');
    }

    public function testMediaRecorderCodecParametersAreNormalizedAndValidated(): void
    {
        self::assertSame('audio/webm', MediaService::baseMimeType('audio/webm;codecs=opus'));
        self::assertTrue(MediaService::isMimeAllowedForKind(MediaKind::Voice, 'video/webm', 'audio/webm;codecs=opus'));
        self::assertFalse(MediaService::isMimeAllowedForKind(MediaKind::Voice, 'application/x-dosexec', 'audio/webm;codecs=opus'));
    }
}
