<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Application\StorageError;
use Aws\S3\ObjectCopier;
use Aws\S3\ObjectUploader;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/** Bounded SDK operations shared by transfer verification and the Storage adapter. */
final readonly class S3Objects
{
    public function __construct(private S3ClientFactory $clients, private S3Config $config) {}

    /** @param resource $stream */
    public function put(string $bucket, string $key, $stream, string $contentType = 'application/octet-stream'): void
    {
        try {
            $uploader = new ObjectUploader($this->clients->internal(), $bucket, $key, $stream, null, [
                'part_size' => $this->config->multipartPartSize,
                'mup_threshold' => $this->config->multipartPartSize,
                'concurrency' => $this->config->transferConcurrency,
                'params' => ['ContentType' => $contentType],
            ]);
            $uploader->upload();
        } catch (Throwable $error) {
            throw S3Failure::storage($error);
        }
    }

    /** @return resource */
    public function open(string $bucket, string $key)
    {
        try {
            $result = $this->clients->internal()->getObject([
                'Bucket' => $bucket, 'Key' => $key, '@http' => ['stream' => true],
            ]);
            $body = $result['Body'] ?? null;
            if (!$body instanceof StreamInterface) {
                throw new StorageError(503, 's3_unavailable');
            }
            $stream = $body->detach();
            if (!is_resource($stream)) {
                throw new StorageError(503, 's3_unavailable');
            }
            return $stream;
        } catch (Throwable $error) {
            throw S3Failure::storage($error);
        }
    }

    /** @return array{sizeBytes:int,etag:string}|null */
    public function head(string $bucket, string $key): ?array
    {
        try {
            $result = $this->clients->internal()->headObject(['Bucket' => $bucket, 'Key' => $key]);
            $length = $result['ContentLength'] ?? null;
            $etag = $result['ETag'] ?? '';
            if ((!is_int($length) && (!is_string($length) || !ctype_digit($length))) || !is_string($etag)) {
                throw new StorageError(503, 's3_unavailable');
            }
            return ['sizeBytes' => (int) $length, 'etag' => $etag];
        } catch (Throwable $error) {
            $failure = S3Failure::storage($error);
            if ($failure->reason === 'object_missing') {
                return null;
            }
            throw $failure;
        }
    }

    /** @return array{sha256:string,sizeBytes:int} */
    public function verify(string $bucket, string $key, ?int $expectedSize = null): array
    {
        $stream = $this->open($bucket, $key);
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($stream)) {
                $bytes = fread($stream, 65536);
                if ($bytes === false || ($bytes === '' && !feof($stream))) {
                    throw new StorageError(503, 's3_read_failed');
                }
                $size += strlen($bytes);
                if ($expectedSize !== null && $size > $expectedSize) {
                    throw new StorageError(422, 'size_mismatch');
                }
                hash_update($hash, $bytes);
            }
        } finally {
            fclose($stream);
        }
        return ['sha256' => hash_final($hash), 'sizeBytes' => $size];
    }

    public function copy(string $bucket, string $sourceKey, string $destinationKey): void
    {
        try {
            $copier = new ObjectCopier(
                $this->clients->internal(),
                ['Bucket' => $bucket, 'Key' => $sourceKey],
                ['Bucket' => $bucket, 'Key' => $destinationKey],
                null,
                [
                    'part_size' => $this->config->multipartPartSize,
                    'concurrency' => $this->config->transferConcurrency,
                ],
            );
            $copier->copy();
        } catch (Throwable $error) {
            throw S3Failure::storage($error);
        }
    }

    public function delete(string $bucket, string $key): void
    {
        try {
            $versioning = $this->clients->internal()->getBucketVersioning(['Bucket' => $bucket]);
            if (in_array($versioning['Status'] ?? null, ['Enabled', 'Suspended'], true)) {
                $pages = $this->clients->internal()->getPaginator('ListObjectVersions', [
                    'Bucket' => $bucket, 'Prefix' => $key, 'MaxKeys' => 1000,
                ]);
                foreach ($pages as $page) {
                    foreach (['Versions', 'DeleteMarkers'] as $field) {
                        $versions = $page[$field] ?? [];
                        if (!is_array($versions)) {
                            throw new StorageError(503, 's3_unavailable');
                        }
                        foreach ($versions as $version) {
                            if (!is_array($version) || !is_string($version['Key'] ?? null)
                                || !is_string($version['VersionId'] ?? null)) {
                                throw new StorageError(503, 's3_unavailable');
                            }
                            if ($version['Key'] === $key) {
                                $this->clients->internal()->deleteObject([
                                    'Bucket' => $bucket, 'Key' => $key, 'VersionId' => $version['VersionId'],
                                ]);
                            }
                        }
                    }
                }
                return;
            }
            $this->clients->internal()->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (Throwable $error) {
            throw S3Failure::storage($error);
        }
    }

    /** @return iterable<array{key:string,lastModified:DateTimeImmutable}> */
    public function objectsBefore(string $bucket, string $prefix, DateTimeImmutable $cutoff): iterable
    {
        try {
            $pages = $this->clients->internal()->getPaginator('ListObjectsV2', [
                'Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000,
            ]);
            foreach ($pages as $page) {
                $objects = $page['Contents'] ?? [];
                if (!is_array($objects)) {
                    throw new StorageError(503, 's3_unavailable');
                }
                foreach ($objects as $object) {
                    if (!is_array($object)) {
                        throw new StorageError(503, 's3_unavailable');
                    }
                    $key = $object['Key'] ?? null;
                    if (!is_string($key)) {
                        throw new StorageError(503, 's3_unavailable');
                    }
                    $time = $object['LastModified'] ?? null;
                    $modified = match (true) {
                        $time instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($time),
                        is_string($time) => new DateTimeImmutable($time),
                        default => throw new StorageError(503, 's3_unavailable'),
                    };
                    if ($modified < $cutoff) {
                        yield ['key' => $key, 'lastModified' => $modified];
                    }
                }
            }
        } catch (Throwable $error) {
            throw S3Failure::storage($error);
        }
    }

    public static function objectKey(string $key): string
    {
        if (preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $key) !== 1) {
            throw new StorageError(422, 'invalid_input');
        }
        return 'objects/' . strtolower($key);
    }
}
