<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Storage\Application\StorageScope;
use App\Storage\Infrastructure\S3Objects;
use App\Tests\Foundation\HomeworkFixture;
use Aws\Exception\AwsException;

require_once dirname(__DIR__) . '/Foundation/HomeworkFixture.php';

/** Transactional metadata plus real S3. Teardown deletes only explicitly owned transfer keys. */
abstract class TransferDatabaseFixture extends HomeworkFixture
{
    use S3Fixture;

    private array $storageTransfers = [];
    private array $educationTransfers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startS3Fixture();
        $this->db->createCommand("UPDATE core.modules SET enabled=true,enabled_at=now(),disabled_at=NULL WHERE code='storage'")->execute();
    }

    protected function tearDown(): void
    {
        $objects = [];
        $uploads = [];
        foreach ($this->storageTransfers as $id) {
            $row = $this->db->createCommand('SELECT staging_key,final_storage_key::text AS final,multipart_upload_id AS upload
                FROM storage.transfers WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryOne();
            if (!is_array($row)) {
                continue;
            }
            $objects[] = [$this->s3Config->bucketStorage,$row['staging_key']];
            $objects[] = [$this->s3Config->bucketStorage,S3Objects::objectKey($row['final'])];
            if ($row['upload'] !== null) {
                $uploads[] = $row;
            }
        }
        foreach ($this->educationTransfers as $id) {
            $row = $this->db->createCommand('SELECT staging_key,final_key::text AS final FROM education.file_transfers
                WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryOne();
            if (!is_array($row)) {
                continue;
            }
            $objects[] = [$this->s3Config->bucketEducation,$row['staging_key']];
            $objects[] = [$this->s3Config->bucketEducation,S3Objects::objectKey($row['final'])];
        }
        parent::tearDown();
        foreach ($uploads as $row) {
            try {
                $this->clients->internal()->abortMultipartUpload(['Bucket' => $this->s3Config->bucketStorage,
                    'Key' => $row['staging_key'],'UploadId' => $row['upload']]);
            } catch (AwsException $error) {
                if (!in_array($error->getAwsErrorCode(), ['NoSuchUpload','NoSuchKey'], true)) {
                    throw $error;
                }
            }
        }
        foreach ($objects as [$bucket,$key]) {
            $this->objects->delete($bucket, $key);
        }
        $this->finishS3Fixture();
    }

    protected function storageScope(?array $student = null): StorageScope
    {
        $scope = ($student ?? $this->learner)['scope'];
        return new StorageScope(
            $scope->organizationId,
            $scope->accountId,
            $scope->personId,
            $scope->locale,
            $scope->timezone,
            $scope->studentId,
        );
    }

    protected function trackStorage(array $transfer): array
    {
        $this->storageTransfers[] = $transfer['id'];
        return $transfer;
    }

    protected function trackEducation(array $transfer): array
    {
        $this->educationTransfers[] = $transfer['id'];
        return $transfer;
    }

    protected function objectRequest(array $request, ?string $bytes = null, array $extraHeaders = []): array
    {
        $request['headers'] = (array) $request['headers'];
        foreach (array_keys($request['headers']) as $header) {
            self::assertNotContains(strtolower($header), ['authorization','cookie']);
        }
        self::assertContains($request['method'], ['GET','PUT']);
        return $this->rawHttp($request['url'], $request['method'], $bytes, [...$request['headers'],...$extraHeaders]);
    }

    protected function objectHash(string $bucket, string $key): string
    {
        return $this->objects->verify($bucket, $key)['sha256'];
    }

    protected function storageBucket(): string
    {
        return $this->s3Config->bucketStorage;
    }
    protected function educationBucket(): string
    {
        return $this->s3Config->bucketEducation;
    }
    protected function multipartSize(): int
    {
        return $this->s3Config->multipartPartSize;
    }

    protected function writeObject(string $bucket, string $key, string $bytes): void
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        try {
            self::assertSame(strlen($bytes), fwrite($stream, $bytes));
            rewind($stream);
            $this->objects->put($bucket, $key, $stream, 'application/octet-stream');
        } finally {
            fclose($stream);
        }
    }
}
