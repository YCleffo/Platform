<?php

declare(strict_types=1);

namespace App\Storage\Application;

use App\DeviceManagement\Application\DeviceStore;
use DateTimeImmutable;
use DateTimeZone;

final readonly class StorageService
{
    private const MAX_MANIFEST_ENTRIES = 10000;

    public function __construct(
        private StorageStore $store,
        private StorageFileAdapter $files,
        private Mutation $mutations,
        private DeviceStore $devices,
        private int $maxFileSize = 5368709120,
    ) {}

    /** @return array<string,mixed> */
    public function read(string $operation, StorageScope $scope, array $query, ?string $id = null): array
    {
        $offset = max(0, (int) ($query['offset'] ?? 0));
        return match ($operation) {
            'space' => $this->store->space($scope),
            'files' => $this->pageOf(fn(): array => $this->store->files($scope, $offset), $offset),
            'conflicts' => $this->pageOf(fn(): array => $this->store->conflicts($scope, $offset), $offset),
            'file_versions' => ['items' => $this->store->fileVersions($scope, self::path($query['path'] ?? null))],
            default => throw new StorageError(404, 'not_found'),
        };
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function write(string $operation, StorageScope $scope, array $body, ?string $id = null): array
    {
        return match ($operation) {
            'rename_file' => $this->renameFile($scope, $body),
            'resolve_conflict' => $this->resolveConflict($scope, self::uuid($id), $body),
            'ticket' => $this->ticket($scope, $body),
            'restore_file' => $this->restoreFile($scope, $body),
            'delete_file' => $this->deleteFile($scope, $body),
            default => throw new StorageError(404, 'not_found'),
        };
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    private function restoreFile(StorageScope $scope, array $body): array
    {
        self::only($body, ['path', 'version']);
        $path = self::path($body['path'] ?? null);
        $version = (int) ($body['version'] ?? 0);
        if ($version < 1) {
            throw new StorageError(422, 'invalid_input');
        }
        $file = $this->store->fileByPath($scope, $path) ?? throw new StorageError(404, 'not_found');
        $historical = $this->store->versionByNumber($scope, (string) $file['id'], $version)
            ?? throw new StorageError(404, 'not_found');

        $sourceKey = (string) $historical['storageKey'];
        $newKey = $this->newKey();
        if ($this->files instanceof StorageObjectAdapter) {
            $verified = $this->files->verify($sourceKey);
            if (!hash_equals((string) $historical['sha256'], $verified['sha256']) || $verified['sizeBytes'] !== (int) $historical['sizeBytes']) {
                throw new StorageError(409, 'checksum_mismatch');
            }
            $this->files->copy($sourceKey, $newKey);
            $copy = $this->files->verify($newKey);
            if ($copy !== $verified) {
                throw new StorageError(409, 'checksum_mismatch');
            }
        } else {
            $stream = $this->files->open($sourceKey);
            try {
                if (!hash_equals((string) $historical['sha256'], $this->files->hash($stream))
                    || $this->streamSize($stream) !== (int) $historical['sizeBytes']) {
                    throw new StorageError(409, 'checksum_mismatch');
                }
                $this->files->write($newKey, $stream);
            } finally {
                fclose($stream);
            }
        }
        try {
            return $this->mutations->run($scope, 'restore', fn(): array => $this->store->restoreVersion($scope, $path, $version, $newKey));
        } catch (\Throwable $e) {
            $this->files->delete($newKey);
            throw $e;
        }
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    private function deleteFile(StorageScope $scope, array $body): array
    {
        self::only($body, ['path']);
        $path = self::path($body['path'] ?? null);
        return $this->mutations->run($scope, 'delete', function () use ($scope, $path): array {
            $this->store->deleteFile($scope, $path);
            return ['path' => $path, 'deleted' => true];
        });
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    private function renameFile(StorageScope $scope, array $body): array
    {
        self::only($body, ['path', 'newPath']);
        return $this->mutations->run($scope, 'rename', fn(): array => $this->store->renameFile(
            $scope,
            self::path($body['path'] ?? null),
            self::path($body['newPath'] ?? null),
        ));
    }

    private function resolveConflict(StorageScope $scope, string $id, array $body): array
    {
        self::only($body, ['resolution']);
        if (($body['resolution'] ?? '') !== 'keep_server') {
            throw new StorageError(422, 'invalid_input');
        }
        return $this->mutations->run($scope, 'resolve_conflict', fn(): array => $this->store->resolveConflict($scope, $id, 'keep_server'));
    }

    private function ticket(StorageScope $scope, array $body): array
    {
        self::only($body, ['purpose', 'path', 'expectedSha256', 'expectedSize']);
        $purpose = self::text($body['purpose'] ?? null, 8);
        if (!in_array($purpose, ['upload', 'download'], true)) {
            throw new StorageError(422, 'invalid_input');
        }
        $path = self::path($body['path'] ?? null);
        $size = $body['expectedSize'] ?? null;
        $sha = isset($body['expectedSha256']) ? self::sha256($body['expectedSha256']) : null;
        if ($size !== null && (!is_int($size) || $size < 0 || $size > $this->maxFileSize)) {
            throw new StorageError(422, 'invalid_input');
        }
        if (($size === null) !== ($sha === null)) {
            throw new StorageError(422, 'invalid_input');
        }
        return $this->store->createTicket($scope, $purpose, $path, $sha, $size);
    }

    /**
     * @param resource $stream
     * @return array<string,mixed>
     */
    public function uploadWithTicket(string $token, $stream, ?string $claimedSha256, ?string $deviceId = null, ?StorageScope $actorScope = null): array
    {
        $ticket = $this->store->findTicket(hash('sha256', $token));
        $this->assertTicketUsable($ticket, 'upload');
        $scope = $actorScope ?? $this->deviceScope((string) $ticket['spaceId']);
        if ($actorScope !== null && (string) $this->store->space($actorScope)['id'] !== (string) $ticket['spaceId']) {
            throw new StorageError(404, 'not_found');
        }
        $this->assertDeviceScope($scope, $deviceId, $ticket);
        $sha256 = $this->files->hash($stream);
        $size = $this->streamSize($stream);
        if ($size > $this->maxFileSize) {
            throw new StorageError(413, 'file_too_large');
        }
        if ($claimedSha256 !== null && !hash_equals($sha256, strtolower($claimedSha256))) {
            throw new StorageError(422, 'invalid_input');
        }
        if ($ticket['expectedSha256'] !== null
            && !hash_equals((string) $ticket['expectedSha256'], $sha256)) {
            throw new StorageError(422, 'checksum_mismatch');
        }
        if ($ticket['expectedSize'] !== null && (int) $ticket['expectedSize'] !== $size) {
            throw new StorageError(422, 'size_mismatch');
        }
        $key = $this->newKey();
        $this->files->write($key, $stream);
        try {
            $result = $this->store->commitUpload(
                (string) $ticket['id'],
                $scope,
                (string) $ticket['path'],
                $size,
                $sha256,
                $key,
            );
        } catch (\Throwable $failure) {
            $this->files->delete($key);
            throw $failure;
        }
        if ($result['reused'] ?? false) {
            $this->files->delete($key);
        }
        return ['path' => (string) ($ticket['path'] ?? ''), 'version' => (int) $result['version'],
            'sha256' => $sha256, 'size' => $size];
    }

    /**
     * @return array{stream:resource,size:int,sha256:string,name:string}
     */
    public function downloadWithTicket(string $token, ?string $deviceId = null, ?StorageScope $actorScope = null): array
    {
        $ticket = $this->store->findTicket(hash('sha256', $token));
        $this->assertTicketUsable($ticket, 'download');
        $scope = $actorScope ?? $this->deviceScope((string) $ticket['spaceId']);
        if ($actorScope !== null && (string) $this->store->space($actorScope)['id'] !== (string) $ticket['spaceId']) {
            throw new StorageError(404, 'not_found');
        }
        $this->assertDeviceScope($scope, $deviceId, $ticket);
        if (!$this->store->claimTicket((string) $ticket['id'])) {
            throw new StorageError(404, 'not_found');
        }
        $file = $ticket['fileVersionId'] !== null
            ? $this->store->versionById($scope, (string) $ticket['fileVersionId'])
            : $this->store->fileByPath($scope, (string) $ticket['path']);
        if ($file === null) {
            throw new StorageError(404, 'not_found');
        }
        if ($ticket['expectedSha256'] !== null
            && !hash_equals((string) $ticket['expectedSha256'], (string) $file['sha256'])) {
            throw new StorageError(409, 'ticket_stale');
        }
        if ($ticket['expectedSize'] !== null && (int) $ticket['expectedSize'] !== (int) $file['sizeBytes']) {
            throw new StorageError(409, 'ticket_stale');
        }
        $stream = $this->files->open((string) $file['storageKey']);
        return ['stream' => $stream, 'size' => (int) $file['sizeBytes'], 'sha256' => (string) $file['sha256'],
            'name' => basename(str_replace('\\', '/', (string) $ticket['path']))];
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function agentManifest(string $deviceId, array $body): array
    {
        self::only($body, ['version', 'entries']);
        $scope = $this->deviceSpaceScope($deviceId);
        $version = (int) ($body['version'] ?? 0);
        if ($version < 1 || $version > 999999) {
            throw new StorageError(422, 'invalid_input');
        }
        $rawEntries = $body['entries'] ?? null;
        /** @var list<mixed> $entries */
        $entries = is_array($rawEntries) ? array_values($rawEntries) : [];
        if (count($entries) > self::MAX_MANIFEST_ENTRIES) {
            throw new StorageError(422, 'invalid_input');
        }
        $validated = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['op'])
                || !in_array($entry['op'], ['add', 'update', 'delete'], true)) {
                throw new StorageError(422, 'invalid_input');
            }
            $path = self::path($entry['path']);
            $operation = $entry['op'];
            if ($operation === 'delete') {
                $validated[] = ['path' => $path, 'op' => $operation,
                    'baseSha256' => empty($entry['baseSha256']) ? null : self::sha256($entry['baseSha256'])];
                continue;
            }
            $sha256 = self::sha256($entry['sha256'] ?? null);
            $size = $entry['size'] ?? null;
            if (!is_int($size) || $size < 0 || $size > $this->maxFileSize) {
                throw new StorageError(422, 'invalid_input');
            }
            $validated[] = ['path' => $path, 'op' => $operation, 'sha256' => $sha256, 'size' => $size,
                'baseSha256' => empty($entry['baseSha256']) ? null : self::sha256($entry['baseSha256'])];
        }
        return $this->store->mergeManifest($scope, $version, $validated, $deviceId);
    }

    /** Archive only an explicitly resolved recovery manifest; never confirm or delete bytes. */
    public function agentRebaseManifest(string $deviceId, array $body): array
    {
        self::only($body, ['version']);
        $version = $body['version'] ?? null;
        if (!is_int($version) || $version < 1 || $version > 999999) {
            throw new StorageError(422, 'invalid_input');
        }
        return $this->store->rebaseManifest($this->deviceSpaceScope($deviceId), $version, $deviceId);
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function agentTicket(string $deviceId, array $body): array
    {
        self::only($body, ['purpose', 'path', 'expectedSha256', 'expectedSize']);
        $scope = $this->deviceSpaceScope($deviceId);
        $purpose = self::text($body['purpose'] ?? null, 8);
        if (!in_array($purpose, ['upload', 'download'], true)) {
            throw new StorageError(422, 'invalid_input');
        }
        $path = self::path($body['path'] ?? null);
        $sha256 = self::sha256($body['expectedSha256'] ?? null);
        $size = $body['expectedSize'] ?? null;
        if (!is_int($size) || $size < 0 || $size > $this->maxFileSize) {
            throw new StorageError(422, 'invalid_input');
        }
        if ($purpose === 'download') {
            $file = $this->store->fileVersionByIdentifier($scope, $path, $sha256, $size);
            if ($file === null) {
                throw new StorageError(409, 'ticket_stale');
            }
            return $this->store->createDeviceTicket(
                (string) $this->store->space($scope)['id'],
                $purpose,
                $path,
                $sha256,
                $size,
                (string) $file['versionId'],
                $deviceId,
            );
        }
        return $this->store->createDeviceTicket(
            (string) $this->store->space($scope)['id'],
            $purpose,
            $path,
            $sha256,
            $size,
            null,
            $deviceId,
        );
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    public function agentSync(string $deviceId, array $body): array
    {
        self::only($body, ['version', 'entries']);
        $scope = $this->deviceSpaceScope($deviceId);
        $version = (int) ($body['version'] ?? 0);
        if ($version < 0 || $version > 999999) {
            throw new StorageError(422, 'invalid_input');
        }
        $rawEntries = $body['entries'] ?? null;
        /** @var list<mixed> $entries */
        $entries = is_array($rawEntries) ? array_values($rawEntries) : [];
        if (count($entries) > self::MAX_MANIFEST_ENTRIES) {
            throw new StorageError(422, 'invalid_input');
        }
        $snapshot = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['sha256'], $entry['size'])) {
                throw new StorageError(422, 'invalid_input');
            }
            $size = $entry['size'];
            if (!is_int($size) || $size < 0 || $size > $this->maxFileSize) {
                throw new StorageError(422, 'invalid_input');
            }
            $snapshot[] = ['path' => self::path($entry['path']),
                'sha256' => self::sha256($entry['sha256']), 'size' => $size];
        }
        return $this->store->confirmManifest($scope, $version, $snapshot, $deviceId);
    }

    /** @return array<string,mixed> */
    public function agentSpace(string $deviceId): array
    {
        $scope = $this->deviceSpaceScope($deviceId);
        return $this->store->space($scope);
    }

    /** @return array{version:int,entries:list<array<string,mixed>>} */
    public function agentConfirmedManifest(string $deviceId): array
    {
        $scope = $this->deviceSpaceScope($deviceId);
        return $this->store->confirmedManifest($scope);
    }

    public function maxUploadBytes(): int
    {
        return $this->maxFileSize;
    }

    public function transferScopeForDevice(string $deviceId): StorageScope
    {
        return $this->deviceSpaceScope($deviceId);
    }

    private function deviceScope(string $spaceId): StorageScope
    {
        $space = $this->store->spaceById($spaceId) ?? throw new StorageError(404, 'not_found');
        return new StorageScope(
            (string) $space['organizationId'],
            '',
            '',
            'ru-RU',
            'UTC',
            (string) $space['studentId'],
        );
    }

    private function deviceSpaceScope(string $deviceId): StorageScope
    {
        $deviceId = self::uuid($deviceId);
        $session = $this->devices->activeSession($deviceId);
        if ($session === null) {
            throw new StorageError(404, 'not_found');
        }
        return new StorageScope(
            (string) $session['organizationId'],
            '',
            '',
            'ru-RU',
            'UTC',
            (string) $session['studentId'],
        );
    }

    /**
     * @param array<string,mixed>|null $ticket
     */
    private function assertTicketUsable(?array $ticket, string $purpose): void
    {
        if ($ticket === null || $ticket['usedAt'] !== null || $ticket['purpose'] !== $purpose
            || new DateTimeImmutable((string) $ticket['expiresAt']) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new StorageError(404, 'not_found');
        }
    }

    /**
     * @param array<string,mixed> $ticket
     */
    private function assertDeviceScope(StorageScope $scope, ?string $deviceId, array $ticket): void
    {
        if ($deviceId === null) {
            return;
        }
        $session = $this->devices->activeSession($deviceId);
        if ($session === null
            || (string) $session['organizationId'] !== $scope->organizationId
            || (string) $session['studentId'] !== $scope->studentId
            || ($ticket['deviceId'] !== null && (string) $ticket['deviceId'] !== $deviceId)) {
            throw new StorageError(404, 'not_found');
        }
    }

    private function newKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @param resource $stream
     */
    private function streamSize($stream): int
    {
        $stats = fstat($stream);
        $size = is_array($stats) && isset($stats['size']) ? $stats['size'] : 0;
        rewind($stream);
        return $size;
    }

    /**
     * @param callable():list<array<string,mixed>> $fetch
     * @return array<string,mixed>
     */
    private function pageOf(callable $fetch, int $offset): array
    {
        $items = $fetch();
        return ['items' => array_slice($items, 0, 50), 'limit' => 50, 'offset' => $offset,
            'nextOffset' => count($items) > 50 ? $offset + 50 : null];
    }

    /** @param array<array-key,mixed> $body */
    private static function only(array $body, array $keys): void
    {
        if (array_diff(array_keys($body), $keys) !== []) {
            throw new StorageError(422, 'invalid_input');
        }
    }

    private static function text(mixed $value, int $maximum): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new StorageError(422, 'invalid_input');
        }
        return trim($value);
    }

    private static function sha256(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/i', $value) !== 1) {
            throw new StorageError(422, 'invalid_input');
        }
        return strtolower($value);
    }

    private static function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new StorageError(422, 'invalid_input');
        }
        return $value;
    }

    private static function path(mixed $value): string
    {
        $path = self::text($value, 1024);
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '' || str_starts_with($normalized, '/') || str_ends_with($normalized, '/')
            || preg_match('~(^|/)\.\.?(/|$)~', $normalized) === 1 || preg_match('/[\x00-\x1F]/', $normalized) === 1) {
            throw new StorageError(422, 'invalid_input');
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || rtrim($segment, " .") !== $segment || preg_match('/[:*?"<>|]/', $segment) === 1
                || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\\.|$)/i', $segment) === 1) {
                throw new StorageError(422, 'invalid_input');
            }
        }
        return $normalized;
    }
}
