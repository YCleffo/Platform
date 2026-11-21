<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Storage\Application\StorageObjectAdapter;
use App\Storage\Infrastructure\S3ClientFactory;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Objects;
use App\Storage\Infrastructure\S3StorageFileAdapter;
use DateTimeImmutable;

/** Tracks only this test's UUIDs, so teardown never enumerates or deletes another test's objects. */
final class ObservedS3Objects implements StorageObjectAdapter
{
    private S3StorageFileAdapter $adapter;
    /** @var array<string,true> */
    private array $owned = [];

    public function __construct()
    {
        $config = new S3Config();
        IsolatedS3Runtime::assertConfigured($config);
        $this->adapter = new S3StorageFileAdapter(new S3Objects(new S3ClientFactory($config), $config), $config);
    }
    public function write(string $key, $stream): void
    {
        $this->owned[$key] = true;
        $this->adapter->write($key, $stream);
    }
    public function open(string $key)
    {
        return $this->adapter->open($key);
    }
    public function delete(string $key): void
    {
        $this->adapter->delete($key);
    }
    public function exists(string $key): bool
    {
        return $this->adapter->exists($key);
    }
    public function hash($stream): string
    {
        return $this->adapter->hash($stream);
    }
    public function verify(string $key): array
    {
        return $this->adapter->verify($key);
    }
    public function copy(string $sourceKey, string $destinationKey): void
    {
        $this->owned[$destinationKey] = true;
        $this->adapter->copy($sourceKey, $destinationKey);
    }
    public function keysBefore(DateTimeImmutable $cutoff): iterable
    {
        foreach ($this->adapter->keysBefore($cutoff) as $key) {
            if (isset($this->owned[$key])) {
                yield $key;
            }
        }
    }
    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_filter(array_keys($this->owned), fn(string $key): bool => $this->exists($key)));
    }
    public function removeOwned(): void
    {
        foreach (array_keys($this->owned) as $key) {
            $this->adapter->delete($key);
        }
    }
}
