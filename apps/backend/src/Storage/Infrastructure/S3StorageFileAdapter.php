<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Application\StorageError;
use App\Storage\Application\StorageObjectAdapter;
use DateTimeImmutable;

final readonly class S3StorageFileAdapter implements StorageObjectAdapter
{
    public function __construct(private S3Objects $objects, private S3Config $config) {}

    public function write(string $key, $stream): void
    {
        if (!is_resource($stream)) {
            throw new StorageError(422, 'invalid_stream');
        }
        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        }
        $this->objects->put($this->config->bucketStorage, S3Objects::objectKey($key), $stream);
    }

    public function open(string $key)
    {
        return $this->objects->open($this->config->bucketStorage, S3Objects::objectKey($key));
    }

    public function delete(string $key): void
    {
        $this->objects->delete($this->config->bucketStorage, S3Objects::objectKey($key));
    }

    public function exists(string $key): bool
    {
        return $this->objects->head($this->config->bucketStorage, S3Objects::objectKey($key)) !== null;
    }

    public function hash($stream): string
    {
        if (!is_resource($stream) || !stream_get_meta_data($stream)['seekable']) {
            throw new StorageError(422, 'seekable_stream_required');
        }
        rewind($stream);
        $hash = hash_init('sha256');
        if (hash_update_stream($hash, $stream) === false) {
            throw new StorageError(503, 'stream_read_failed');
        }
        rewind($stream);
        return hash_final($hash);
    }

    public function verify(string $key): array
    {
        return $this->objects->verify($this->config->bucketStorage, S3Objects::objectKey($key));
    }

    public function copy(string $sourceKey, string $destinationKey): void
    {
        $this->objects->copy($this->config->bucketStorage,
            S3Objects::objectKey($sourceKey), S3Objects::objectKey($destinationKey));
    }

    public function keysBefore(DateTimeImmutable $cutoff): iterable
    {
        foreach ($this->objects->objectsBefore($this->config->bucketStorage, 'objects/', $cutoff) as $object) {
            $key = substr($object['key'], strlen('objects/'));
            if (preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $key) === 1) {
                yield $key;
            }
        }
    }
}
