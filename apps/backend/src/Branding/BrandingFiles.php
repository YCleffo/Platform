<?php

declare(strict_types=1);

namespace App\Branding;

use App\Core\Client\Domain\ClientContext;
use App\Core\Settings\Application\SettingsService;
use App\Storage\Application\StorageError;
use App\Storage\Infrastructure\S3ClientFactory;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Failure;
use App\Storage\Infrastructure\S3Objects;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Public installation icons use the same private object infrastructure as other files. */
final readonly class BrandingFiles
{
    public const MAX_BYTES = 524288;

    public function __construct(
        private ConnectionInterface $db,
        private SettingsService $settings,
        private BrandingSettings $branding,
        private S3Config $config,
        private S3ClientFactory $clients,
        private S3Objects $objects,
    ) {}

    /** @param array<array-key,mixed> $input */
    public function start(array $input, string $actor, ClientContext $context): array
    {
        $size = $input['sizeBytes'] ?? null;
        $sha256 = $input['sha256'] ?? null;
        if (array_diff(array_keys($input), ['sizeBytes', 'sha256']) !== []
            || !is_int($size) || $size < 1 || $size > self::MAX_BYTES
            || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new StorageError(422, 'invalid_favicon');
        }
        $id = (string) $this->db->createCommand('SELECT uuidv7()::text')->queryScalar();
        $pending = [
            'id' => $id, 'actor' => $actor, 'status' => 'pending',
            'sizeBytes' => $size, 'sha256' => $sha256,
            'expiresAt' => (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
        ];
        $request = $this->sign($id, $size);
        $this->db->transaction(function () use ($pending, $actor, $context): void {
            $this->lock();
            $this->settings->putSystemSetting(
                BrandingSettings::UPLOAD,
                $pending,
                false,
                'Pending installation icon upload',
                $actor,
                $context,
            );
        });
        return ['id' => $id, 'expiresAt' => $pending['expiresAt'], 'request' => $request];
    }

    public function refresh(string $id, string $actor): array
    {
        $pending = $this->pending($id, $actor);
        return ['id' => $id, 'expiresAt' => $pending['expiresAt'], 'request' => $this->sign($id, $pending['sizeBytes'])];
    }

    public function complete(string $id, string $actor, ClientContext $context): array
    {
        return $this->db->transaction(function () use ($id, $actor, $context): array {
            $this->lock();
            $pending = $this->pending($id, $actor, true);
            if ($pending['status'] === 'confirmed') {
                return self::confirmedResult($pending['result'] ?? null);
            }
            $stream = $this->objects->open($this->config->bucketStorage, $this->stagingKey($id));
            try {
                $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
            } finally {
                fclose($stream);
            }
            if (!is_string($bytes) || strlen($bytes) !== $pending['sizeBytes']
                || !hash_equals($pending['sha256'], hash('sha256', $bytes))) {
                throw new StorageError(422, 'favicon_integrity_mismatch');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if ($mime === 'image/x-icon') {
                $mime = 'image/vnd.microsoft.icon';
            }
            $image = @getimagesizefromstring($bytes);
            if (!in_array($mime, ['image/png', 'image/vnd.microsoft.icon'], true) || $image === false
                || $image[0] < 1 || $image[1] < 1 || $image[0] > 1024 || $image[1] > 1024) {
                throw new StorageError(422, 'invalid_favicon');
            }
            $old = $this->branding->favicon();
            $temporary = fopen('php://temp/maxmemory:524288', 'w+b');
            if ($temporary === false) {
                throw new StorageError(503, 'temporary_stream_unavailable');
            }
            try {
                if (fwrite($temporary, $bytes) !== strlen($bytes)) {
                    throw new StorageError(503, 'temporary_stream_unavailable');
                }
                rewind($temporary);
                $this->objects->put($this->config->bucketStorage, 'branding/' . $id, $temporary, $mime);
            } finally {
                fclose($temporary);
            }
            $verified = $this->objects->verify($this->config->bucketStorage, 'branding/' . $id, $pending['sizeBytes']);
            if (!hash_equals($pending['sha256'], $verified['sha256'])) {
                throw new StorageError(503, 'favicon_integrity_mismatch');
            }
            $icon = ['id' => $id, 'mime' => $mime, 'sha256' => $pending['sha256'], 'sizeBytes' => $pending['sizeBytes']];
            $this->settings->putSystemSetting(BrandingSettings::FAVICON, $icon, true, 'Installation favicon', $actor, $context);
            $result = $this->branding->read();
            $pending['status'] = 'confirmed';
            $pending['result'] = $result;
            $this->settings->putSystemSetting(
                BrandingSettings::UPLOAD,
                $pending,
                false,
                'Confirmed installation icon upload',
                $actor,
                $context,
            );
            if ($old !== null && $old['id'] !== $id) {
                $this->queueIcon($old['id']);
            }
            $this->queueStaging($id);
            return $result;
        });
    }

    public function remove(string $actor, ClientContext $context): array
    {
        return $this->db->transaction(function () use ($actor, $context): array {
            $this->lock();
            $old = $this->branding->favicon();
            $this->settings->deleteSystemSetting(BrandingSettings::FAVICON, $actor, $context);
            if ($old !== null) {
                $this->queueIcon($old['id']);
            }
            return $this->branding->read();
        });
    }

    public function abort(string $id, string $actor, ClientContext $context): array
    {
        return $this->db->transaction(function () use ($id, $actor, $context): array {
            $this->lock();
            $pending = $this->settings->getSystemSetting(BrandingSettings::UPLOAD);
            if (!BrandingSettings::uuid($id) || !is_array($pending) || ($pending['id'] ?? null) !== $id
                || ($pending['actor'] ?? null) !== $actor) {
                throw new StorageError(404, 'not_found');
            }
            if (($pending['status'] ?? null) === 'confirmed') {
                return self::confirmedResult($pending['result'] ?? null);
            }
            if (($pending['status'] ?? null) !== 'aborted') {
                $pending['status'] = 'aborted';
                $this->settings->putSystemSetting(
                    BrandingSettings::UPLOAD,
                    $pending,
                    false,
                    'Cancelled installation icon upload',
                    $actor,
                    $context,
                );
                $this->queueStaging($id);
            }
            return $this->branding->read();
        });
    }

    /** @return array{id:string,mime:string,sha256:string,sizeBytes:int,stream:resource} */
    public function open(string $id): array
    {
        $icon = $this->branding->favicon();
        if ($icon === null || !hash_equals($icon['id'], $id)) {
            throw new StorageError(404, 'not_found');
        }
        return [...$icon, 'stream' => $this->objects->open($this->config->bucketStorage, 'branding/' . $id)];
    }

    /** @return array{id:string,actor:string,status:'pending'|'confirmed',sizeBytes:int,sha256:string,expiresAt:string,result?:mixed} */
    private function pending(string $id, string $actor, bool $allowConfirmed = false): array
    {
        $pending = $this->settings->getSystemSetting(BrandingSettings::UPLOAD);
        if (!BrandingSettings::uuid($id) || !is_array($pending) || ($pending['id'] ?? null) !== $id
            || ($pending['actor'] ?? null) !== $actor) {
            throw new StorageError(404, 'not_found');
        }
        $status = $pending['status'] ?? null;
        $size = $pending['sizeBytes'] ?? null;
        $sha256 = $pending['sha256'] ?? null;
        $expiresAt = $pending['expiresAt'] ?? null;
        if (!is_int($size) || $size < 1 || $size > self::MAX_BYTES || !is_string($sha256) || !is_string($expiresAt)) {
            throw new StorageError(409, 'favicon_upload_expired');
        }
        $value = ['id' => $id, 'actor' => $actor, 'sizeBytes' => $size, 'sha256' => $sha256, 'expiresAt' => $expiresAt];
        if ($allowConfirmed && $status === 'confirmed') {
            return [...$value, 'status' => 'confirmed', 'result' => $pending['result'] ?? null];
        }
        if ($status !== 'pending' || new DateTimeImmutable($expiresAt) <= new DateTimeImmutable()) {
            throw new StorageError(409, 'favicon_upload_expired');
        }
        return [...$value, 'status' => 'pending'];
    }

    /** @return array<array-key,mixed> */
    private static function confirmedResult(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['name'] ?? null) || !array_key_exists('favicon', $value)) {
            throw new StorageError(503, 'favicon_integrity_mismatch');
        }
        return $value;
    }

    private function sign(string $id, int $size): array
    {
        try {
            $this->clients->assertBucket($this->config->bucketStorage);
            $expires = new DateTimeImmutable('+' . $this->config->presignTtlSeconds . ' seconds');
            $command = $this->clients->public()->getCommand('PutObject', [
                'Bucket' => $this->config->bucketStorage, 'Key' => $this->stagingKey($id),
                'ContentLength' => $size, 'ContentType' => 'application/octet-stream',
            ]);
            $request = $this->clients->public()->createPresignedRequest($command, $expires);
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                if (!in_array(strtolower((string) $name), ['host', 'content-length', 'user-agent'], true)) {
                    $headers[(string) $name] = implode(', ', $values);
                }
            }
            return ['url' => (string) $request->getUri(), 'method' => 'PUT',
                'headers' => (object) $headers, 'expiresAt' => $expires->format(DATE_ATOM)];
        } catch (\Throwable $error) {
            throw S3Failure::storage($error);
        }
    }

    private function stagingKey(string $id): string
    {
        return 'staging/' . $id . '/branding';
    }

    private function queueIcon(string $id): void
    {
        $this->db->createCommand('INSERT INTO storage.object_cleanup(object_key) VALUES(:key)
            ON CONFLICT(object_key) DO NOTHING', [':key' => 'branding/' . $id])->execute();
    }

    private function queueStaging(string $id): void
    {
        $this->db->createCommand(
            'INSERT INTO storage.object_cleanup(object_key,not_before)
            VALUES(:key,now()+make_interval(secs=>:ttl)) ON CONFLICT(object_key) DO NOTHING',
            [':key' => $this->stagingKey($id), ':ttl' => $this->config->presignTtlSeconds + 60],
        )->execute();
    }

    private function lock(): void
    {
        $this->db->createCommand("SELECT pg_advisory_xact_lock(hashtextextended('platform.branding',0))")->queryScalar();
    }
}
