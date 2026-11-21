<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Storage\Application\StorageError;
use App\Storage\Application\StorageObjectAdapter;
use DateTimeImmutable;

/** Small unit-test values only. Production and integration tests use the actual S3 adapter. */
final class MemoryObjects implements StorageObjectAdapter
{
    /** @var array<string,string> */
    private array $values = [];

    public function write(string $key, $stream): void
    {
        rewind($stream);
        $bytes = stream_get_contents($stream);
        if (!is_string($bytes)) {
            throw new StorageError(503, 'stream_read_failed');
        }
        $this->values[$key] = $bytes;
    }
    public function open(string $key)
    {
        if (!array_key_exists($key, $this->values)) {
            throw new StorageError(404, 'object_missing');
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->values[$key]);
        rewind($stream);
        return $stream;
    }
    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }
    public function hash($stream): string
    {
        rewind($stream);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        rewind($stream);
        return hash_final($hash);
    }
    public function verify(string $key): array
    {
        if (!$this->exists($key)) {
            throw new StorageError(404, 'object_missing');
        }
        return ['sha256' => hash('sha256', $this->values[$key]), 'sizeBytes' => strlen($this->values[$key])];
    }
    public function copy(string $sourceKey, string $destinationKey): void
    {
        if (!$this->exists($sourceKey)) {
            throw new StorageError(404, 'object_missing');
        }
        $this->values[$destinationKey] = $this->values[$sourceKey];
    }
    public function keysBefore(DateTimeImmutable $cutoff): iterable
    {
        yield from $this->keys();
    }
    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->values);
    }
}
