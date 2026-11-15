<?php

declare(strict_types=1);

namespace App\Storage\Application;

use DateTimeImmutable;

/** Object-store capabilities used for verified restore and orphan reconciliation. */
interface StorageObjectAdapter extends StorageFileAdapter
{
    /** @return array{sha256:string,sizeBytes:int} */
    public function verify(string $key): array;
    public function copy(string $sourceKey, string $destinationKey): void;
    /** @return iterable<string> */
    public function keysBefore(DateTimeImmutable $cutoff): iterable;
}
