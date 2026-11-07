<?php

declare(strict_types=1);

namespace App\Education\Application;

use Psr\Http\Message\UploadedFileInterface;

interface FileStore
{
    public function store(UploadedFileInterface $upload, bool $avatar): StoredFile;
    public function path(string $key): string;
    public function remove(string $key): void;
    /** Move bytes aside so a failed database transaction can restore them. */
    public function stageRemoval(string $key): ?string;
    public function restoreRemoval(?string $stagedPath): void;
    public function purgeRemoval(?string $stagedPath): void;
}
