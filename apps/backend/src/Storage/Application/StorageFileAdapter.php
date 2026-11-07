<?php

declare(strict_types=1);

namespace App\Storage\Application;

interface StorageFileAdapter
{
    /** @param resource $stream */
    public function write(string $key, $stream): void;
    /** @return resource */
    public function open(string $key);
    public function delete(string $key): void;
    public function exists(string $key): bool;
    /** @param resource $stream */
    public function hash($stream): string;
}
