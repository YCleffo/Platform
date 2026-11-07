<?php

declare(strict_types=1);

namespace App\Storage\Application;

use App\DeviceManagement\Application\DeviceStore;
use DateTimeImmutable;
use DateTimeZone;

final readonly class StorageService
{
    private const MAX_FILE_SIZE = 268435456;
    private const MAX_MANIFEST_ENTRIES = 10000;

    public function __construct(
        private StorageStore $store,
        private StorageFileAdapter $files,
        private Mutation $mutations,
        private DeviceStore $devices,
    ) {}

    /** @return array<string,mixed> */
    public function read(string $operation, StorageScope $scope, array $query, ?string $id = null): array
    {
        $offset = max(0, (int) ($query['offset'] ?? 0));
        return match ($operation) {
            'space' => $this->store->space($scope),
            'files' => $this->pageOf(fn(): array => $this->store->files($scope, $offset), $offset),
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
            'ticket' => $this->ticket($scope, $body),
            default => throw new StorageError(404, 'not_found'),
        };
    }

    /**
     * @param array<array-key,mixed> $body
     * @return array<string,mixed>
     */
    private function ticket(StorageScope $scope, array $body): array
    {
        self::only($body, ['purpose', 'path']);
        $purpose = self::text($body['purpose'] ?? null, 8);
        if (!in_array($purpose, ['upload', 'download'], true)) {
            throw new StorageError(422, 'invalid_input');
        }
        $path = self::path($body['path'] ?? null);
        return $this->store->createTicket($scope, $purpose, $path, null, null);
    }

    /**
     * @param resource $stream
     * @return array<string,mixed>
     */
    public function uploadWithTicket(string $token, $stream, ?string $claimedSha256, ?string $deviceId = null): array
    {
        $ticket = $this->store->findTicket(hash('sha256', $token));
        $this->assertTicketUsable($ticket, 'upload');
        $scope = $this->deviceScope((string) $ticket['spaceId']);
        $this->assertDeviceScope($scope, $deviceId, $ticket);
        $sha256 = $this->files->hash($stream);
        $size = $this->streamSize($stream);
        if ($size > self::MAX_FILE_SIZE) {
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
            $result = $this->store->commitUpload((string) $ticket['id'], $scope, (string) $ticket['path'],
                $size, $sha256, $key);
        } catch (\Throwable $failure) {
            $this->files->delete($key);
            throw $failure;
        }
        if ($result['reused'] ?? false) {
            $this->files->delete($key);
        }
        return ['path' => $ticket['path'], 'version' => (int) $result['version'],
            'sha256' => $sha256, 'size' => $size];
    }

    /**
     * @return array{stream:resource,size:int,sha256:string,name:string}
     */
    public function downloadWithTicket(string $token, ?string $deviceId = null): array
    {
        $ticket = $this->store->findTicket(hash('sha256', $token));
        $this->assertTicketUsable($ticket, 'download');
        $scope = $this->deviceScope((string) $ticket['spaceId']);
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
        $entries = is_array($body['entries'] ?? null) ? $body['entries'] : [];
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
            $operation = (string) $entry['op'];
            if ($operation === 'delete') {
                $validated[] = ['path' => $path, 'op' => $operation,
                    'baseSha256' => empty($entry['baseSha256']) ? null : self::sha256($entry['baseSha256'])];
                continue;
            }
            $sha256 = self::sha256($entry['sha256'] ?? null);
            $size = $entry['size'] ?? null;
            if (!is_int($size) || $size < 0 || $size > self::MAX_FILE_SIZE) {
                throw new StorageError(422, 'invalid_input');
            }
            $validated[] = ['path' => $path, 'op' => $operation, 'sha256' => $sha256, 'size' => $size,
                'baseSha256' => empty($entry['baseSha256']) ? null : self::sha256($entry['baseSha256'])];
        }
        return $this->store->mergeManifest($scope, $version, $validated, $deviceId);
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
        if (!is_int($size) || $size < 0 || $size > self::MAX_FILE_SIZE) {
            throw new StorageError(422, 'invalid_input');
        }
        if ($purpose === 'download') {
            $file = $this->store->fileVersionByIdentifier($scope, $path, $sha256, $size);
            if ($file === null) {
                throw new StorageError(409, 'ticket_stale');
            }
            return $this->store->createDeviceTicket((string) $this->store->space($scope)['id'], $purpose, $path,
                $sha256, $size, (string) $file['versionId'], $deviceId);
        }
        return $this->store->createDeviceTicket((string) $this->store->space($scope)['id'], $purpose, $path,
            $sha256, $size, null, $deviceId);
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
        $entries = is_array($body['entries'] ?? null) ? $body['entries'] : [];
        if (count($entries) > self::MAX_MANIFEST_ENTRIES) {
            throw new StorageError(422, 'invalid_input');
        }
        $snapshot = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['sha256'], $entry['size'])) {
                throw new StorageError(422, 'invalid_input');
            }
            $size = $entry['size'];
            if (!is_int($size) || $size < 0 || $size > self::MAX_FILE_SIZE) {
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

    private function deviceScope(string $spaceId): StorageScope
    {
        $space = $this->store->spaceById($spaceId) ?? throw new StorageError(404, 'not_found');
        return new StorageScope((string) $space['organizationId'], '', '',
            'ru-RU', 'UTC', (string) $space['studentId']);
    }

    private function deviceSpaceScope(string $deviceId): StorageScope
    {
        $deviceId = self::uuid($deviceId);
        $session = $this->devices->activeSession($deviceId);
        if ($session === null) {
            throw new StorageError(404, 'not_found');
        }
        return new StorageScope((string) $session['organizationId'], '', '',
            'ru-RU', 'UTC', (string) $session['studentId']);
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
        $size = is_array($stats) && isset($stats['size']) ? (int) $stats['size'] : 0;
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
        return $normalized;
    }
}
