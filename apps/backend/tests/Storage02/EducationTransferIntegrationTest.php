<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Education\Application\EducationError;
use App\Education\Application\EducationFileTransfers;

require_once __DIR__ . '/TransferDatabaseFixture.php';

final class EducationTransferIntegrationTest extends TransferDatabaseFixture
{
    public function testDirectMaterialPublicationDownloadAndCurrentActorBinding(): void
    {
        $service = $this->container->get(EducationFileTransfers::class);
        $bytes = "Private teaching material stored in actual S3.\n";
        $pending = $this->trackEducation($service->start($this->teacher, 'file', null,
            ['name' => 'material.txt', 'sizeBytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]));
        self::assertSame(200, $this->objectRequest($pending['request'], $bytes)['status']);
        $done = $service->complete($this->teacher, $pending['id']);
        self::assertSame('confirmed', $done['status']);
        self::assertSame($done, $service->complete($this->teacher, $pending['id']));
        $download = $service->download($this->teacher, $done['result']['id'], false);
        self::assertSame(hash('sha256', $bytes), $download['sha256']);
        self::assertSame($bytes, $this->objectRequest($download['request'])['body']);
        try { $service->read($this->outsider, $pending['id']); self::fail('A foreign actor read the transfer.'); }
        catch (EducationError $error) { self::assertSame(404, $error->status); }
        self::assertSame('confirmed', $service->abort($this->teacher, $pending['id'])['status']);
    }

    public function testServerRejectsIncorrectObjectBytesAndAbortIsIdempotent(): void
    {
        $service = $this->container->get(EducationFileTransfers::class);
        $bytes = 'correct bytes';
        $pending = $this->trackEducation($service->start($this->teacher, 'file', null,
            ['name' => 'material.txt', 'sizeBytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]));
        $key = $this->db->createCommand('SELECT staging_key FROM education.file_transfers WHERE id=CAST(:id AS uuid)',
            [':id' => $pending['id']])->queryScalar();
        $this->writeObject($this->educationBucket(), $key, 'damaged bytes');
        try { $service->complete($this->teacher, $pending['id']); self::fail('Incorrect bytes were published.'); }
        catch (EducationError $error) { self::assertSame(422, $error->status); }
        $aborted = $service->abort($this->teacher, $pending['id']);
        self::assertSame('aborted', $aborted['status']);
        self::assertSame($aborted, $service->abort($this->teacher, $pending['id']));
    }
}
