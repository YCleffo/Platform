<?php

declare(strict_types=1);

namespace App\Storage\Application;

interface StorageStore
{
    /** @return array<string,mixed> */
    public function space(StorageScope $scope): array;
    /** @return list<array<string,mixed>> */
    public function files(StorageScope $scope, int $offset): array;
    /** @return array<string,mixed>|null */
    public function spaceById(string $spaceId): ?array;
    /** @return array<string,mixed>|null */
    public function spaceByStudent(string $organizationId, string $studentId): ?array;
    /**
     * @return array<string,mixed>
     */
    public function createTicket(StorageScope $scope, string $purpose, string $path, ?string $expectedSha256, ?int $expectedSize): array;
    /**
     * @return array<string,mixed>
     */
    public function createDeviceTicket(string $spaceId, string $purpose, string $path, ?string $expectedSha256,
        ?int $expectedSize, ?string $fileVersionId = null, ?string $deviceId = null): array;
    /** @return array<string,mixed>|null */
    public function findTicket(string $tokenHash): ?array;
    public function claimTicket(string $ticketId): bool;
    /** @return array<string,mixed>|null */
    public function fileByPath(StorageScope $scope, string $path): ?array;
    /** @return array<string,mixed>|null */
    public function fileVersionByIdentifier(StorageScope $scope, string $path, ?string $sha256 = null, ?int $size = null): ?array;
    /** @return array<string,mixed>|null */
    public function versionById(StorageScope $scope, string $versionId): ?array;
    /** @return array<string,mixed>|null */
    public function versionByNumber(StorageScope $scope, string $fileId, int $version): ?array;
    /** @return list<array<string,mixed>> */
    public function fileVersions(StorageScope $scope, string $path): array;
    /**
     * @return array<string,mixed>
     */
    public function restoreVersion(StorageScope $scope, string $path, int $version, string $newStorageKey): array;
    public function deleteFile(StorageScope $scope, string $path): void;
    /**
     * @return array<string,mixed>
     */
    public function storeVersion(StorageScope $scope, string $path, int $size, string $sha256, string $storageKey, ?array $deviceVersion): array;
    /**
     * Claims the ticket and stores the verified version atomically under the space lock.
     * @return array<string,mixed>
     */
    public function commitUpload(string $ticketId, StorageScope $scope, string $path, int $size, string $sha256,
        string $storageKey): array;
    /**
     * @param list<array{path:string,op:string,sha256?:?string,size?:int,baseSha256?:?string}> $entries
     * @return array<string,mixed>
     */
    public function mergeManifest(StorageScope $scope, int $version, array $entries, ?string $deviceId): array;
    /** @return array{nextVersion:int,rebased:bool} */
    public function rebaseManifest(StorageScope $scope, int $version, string $deviceId): array;
    /**
     * @param list<array{path:string,sha256:string,size:int}> $snapshot
     * @return array<string,mixed>
     */
    public function confirmManifest(StorageScope $scope, int $version, array $snapshot, ?string $deviceId = null): array;
    /** @return array{version:int,entries:list<array<string,mixed>>} */
    public function confirmedManifest(StorageScope $scope): array;
    public function verifyManifestUpload(string $ticketId, string $path, string $sha256, int $size, string $versionId): void;
    public function recordSync(StorageScope $scope, string $direction, ?string $deviceId, string $status): void;
    public function completeSync(StorageScope $scope, string $direction, ?string $deviceId, string $status): void;
    public function renameFile(StorageScope $scope, string $path, string $newPath): array;
    public function conflicts(StorageScope $scope, int $offset): array;
    public function resolveConflict(StorageScope $scope, string $id, string $resolution): array;
    public function markTicketUsed(string $ticketId): void;
}
