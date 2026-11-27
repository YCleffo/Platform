<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;

interface TrashProviderInterface
{
    public function entityType(): string;

    public function moduleCode(): string;

    /**
     * Soft-deletes the entity after verifying actor authorization and returns snapshot data.
     *
     * @return array{title: string, subtitle: string, metadata: array<string, mixed>, payload: array<string, mixed>}
     */
    public function trash(AuthenticatedIdentity $identity, string $organizationId, string $entityId): array;

    /**
     * Restores the entity inside the active DB transaction.
     *
     * @param array<string, mixed> $trashItem
     * @return array{entityId: string, restoredTitle: string, renamed: bool}
     */
    public function restore(string $organizationId, array $trashItem, bool $renameOnConflict): array;

    /**
     * Permanently removes DB rows for the entity and returns storage keys eligible for S3 cleanup.
     *
     * @param array<string, mixed> $trashItem
     * @return list<string>
     */
    public function purge(string $organizationId, array $trashItem): array;
}
