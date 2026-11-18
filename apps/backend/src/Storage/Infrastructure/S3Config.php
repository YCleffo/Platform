<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Application\StorageError;

/** One configuration source for the application and public signing clients. */
final readonly class S3Config
{
    public string $internalEndpoint;
    public string $publicEndpoint;
    public string $region;
    public string $bucketStorage;
    public string $bucketEducation;
    public bool $pathStyle;
    public int $presignTtlSeconds;
    public int $multipartPartSize;
    public int $uploadMaxBytes;
    public int $transferConcurrency;
    public int $requestTimeoutSeconds;
    public int $maxAttempts;

    public function __construct()
    {
        $this->internalEndpoint = $this->endpoint('S3_ENDPOINT_INTERNAL', 'http://seaweedfs:8333');
        $this->publicEndpoint = $this->endpoint('S3_PUBLIC_ENDPOINT', 'http://localhost:8333');
        $this->region = $this->text('S3_REGION', 'us-east-1');
        $this->bucketStorage = $this->bucket('S3_BUCKET_STORAGE', 'platform-storage');
        $this->bucketEducation = $this->bucket('S3_BUCKET_EDUCATION', 'platform-education');
        $pathStyle = strtolower($this->text('S3_PATH_STYLE', 'true'));
        if (!in_array($pathStyle, ['true', 'false', '1', '0'], true)) {
            throw new StorageError(503, 's3_configuration_invalid');
        }
        $this->pathStyle = in_array($pathStyle, ['true', '1'], true);
        $this->presignTtlSeconds = $this->integer('S3_PRESIGN_TTL_SECONDS', 90, 15, 900);
        $this->multipartPartSize = $this->integer('S3_MULTIPART_PART_SIZE', 8388608, 5242880, 5368709120);
        $this->uploadMaxBytes = $this->integer('S3_UPLOAD_MAX_BYTES', 5368709120, 1, 5497558138880);
        $this->transferConcurrency = $this->integer('S3_TRANSFER_CONCURRENCY', 3, 1, 16);
        $this->requestTimeoutSeconds = $this->integer('S3_REQUEST_TIMEOUT_SECONDS', 120, 2, 600);
        $this->maxAttempts = $this->integer('S3_MAX_ATTEMPTS', 3, 1, 5);
    }

    /** @return array{key: string, secret: string} */
    public function credentials(): array
    {
        return [
            'key' => $this->text('S3_ACCESS_KEY_ID'),
            'secret' => $this->text('S3_SECRET_ACCESS_KEY'),
        ];
    }

    private function text(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        $value = is_string($value) && $value !== '' ? $value : $default;
        if ($value === null) {
            throw new StorageError(503, 's3_configuration_missing');
        }
        if (trim($value) === '' || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new StorageError(503, 's3_configuration_invalid');
        }
        return $value;
    }

    private function integer(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = $this->text($name, (string) $default);
        if (!ctype_digit($value) || strlen($value) > 15 || (int) $value < $minimum || (int) $value > $maximum) {
            throw new StorageError(503, 's3_configuration_invalid');
        }
        return (int) $value;
    }

    private function endpoint(string $name, string $default): string
    {
        $value = rtrim($this->text($name, $default), '/');
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new StorageError(503, 's3_configuration_invalid');
        }
        if ($name === 'S3_PUBLIC_ENDPOINT' && in_array(getenv('APP_ENV'), ['prod', 'production'], true)
            && $parts['scheme'] !== 'https') {
            throw new StorageError(503, 's3_https_required');
        }
        return $value;
    }

    private function bucket(string $name, string $default): string
    {
        $value = $this->text($name, $default);
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $value) !== 1 || str_contains($value, '..')) {
            throw new StorageError(503, 's3_configuration_invalid');
        }
        return $value;
    }
}
