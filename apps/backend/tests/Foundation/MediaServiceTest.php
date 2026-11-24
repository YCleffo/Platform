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
}
