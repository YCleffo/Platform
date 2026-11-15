<?php

declare(strict_types=1);

namespace App\Education\Application;

use Psr\Http\Message\UploadedFileInterface;

interface FileStore
{
    public function store(UploadedFileInterface $upload, bool $avatar): StoredFile;
    /** A verified, seekable temporary stream. Caller closes it or transfers ownership to the response. @return resource */
    public function open(string $key, int $expectedSize, string $expectedSha256);
    public function remove(string $key): void;
    /** Non-destructive removal token; physical removal is queued only after metadata commits. */
    public function stageRemoval(string $key): ?string;
    public function restoreRemoval(?string $stagedPath): void;
    public function purgeRemoval(?string $stagedPath): void;
}
