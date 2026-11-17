<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Application\StorageError;
use App\Storage\Application\StorageFileAdapter;

/**
 * Local filesystem adapter for development. Content is stored under the
 * configured root by opaque UUID keys; logical paths never reach the adapter.
 * Symlinks/reparse points escaping the root are rejected.
 */
final class LocalFilesystemStorageAdapter implements StorageFileAdapter
{
    public function __construct(private readonly string $root)
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0o700, true) && !is_dir($this->root)) {
            throw new StorageError(503, 'storage_unavailable');
        }
    }

    public function write(string $key, $stream): void
    {
        $path = $this->resolve($key);
        $temporary = $this->root . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(8));
        $target = fopen($temporary, 'wb');
        if ($target === false) {
            throw new StorageError(503, 'storage_unavailable');
        }
        rewind($stream);
        stream_copy_to_stream($stream, $target);
        if (!fclose($target)) {
            @unlink($temporary);
            throw new StorageError(503, 'storage_unavailable');
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new StorageError(503, 'storage_unavailable');
        }
    }

    /** @return resource */
    public function open(string $key)
    {
        $path = $this->resolve($key);
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new StorageError(404, 'not_found');
        }
        return $stream;
    }

    public function delete(string $key): void
    {
        $path = $this->resolve($key);
        if (file_exists($path) && !unlink($path) && file_exists($path)) {
            throw new StorageError(503, 'storage_unavailable');
        }
    }

    /** Enumerate only old opaque regular objects; directories and links never become cleanup targets. */
    public function keysBefore(\DateTimeImmutable $cutoff): iterable
    {
        foreach (new \DirectoryIterator($this->root) as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getMTime() >= $cutoff->getTimestamp()) continue;
            $key = $file->getFilename();
            if (preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $key) === 1) yield $key;
        }
    }

    public function exists(string $key): bool
    {
        return file_exists($this->resolve($key));
    }

    public function hash($stream): string
    {
        $context = hash_init('sha256');
        rewind($stream);
        while (!feof($stream)) {
            hash_update($context, (string) fread($stream, 65536));
        }
        rewind($stream);
        return hash_final($context);
    }

    private function resolve(string $key): string
    {
        if (preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $key) !== 1) {
            throw new StorageError(422, 'invalid_input');
        }
        $path = realpath($this->root);
        if ($path === false) {
            throw new StorageError(503, 'storage_unavailable');
        }
        return $path . DIRECTORY_SEPARATOR . $key;
    }
}
