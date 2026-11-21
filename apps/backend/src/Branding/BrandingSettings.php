<?php

declare(strict_types=1);

namespace App\Branding;

use App\Core\Client\Domain\ClientContext;
use App\Core\Settings\Application\SettingsService;
use App\Storage\Application\StorageError;

final readonly class BrandingSettings
{
    public const NAME = 'platform.name';
    public const FAVICON = 'platform.favicon';
    public const UPLOAD = 'platform.favicon.upload';

    public function __construct(private SettingsService $settings) {}

    public function read(): array
    {
        $name = $this->settings->getSystemSetting(self::NAME);
        $icon = $this->favicon();
        return [
            'name' => is_string($name) && self::validName($name) ? $name : 'Platform',
            'favicon' => $icon === null ? null : [
                'url' => '/api/branding/favicon/' . $icon['id'],
                'mime' => $icon['mime'],
                'sha256' => $icon['sha256'],
            ],
        ];
    }

    /** @return array{id:string,mime:'image/png'|'image/vnd.microsoft.icon',sha256:string,sizeBytes:int}|null */
    public function favicon(): ?array
    {
        $icon = $this->settings->getSystemSetting(self::FAVICON);
        if (!is_array($icon)) {
            return null;
        }
        $id = $icon['id'] ?? null;
        $mime = $icon['mime'] ?? null;
        $sha256 = $icon['sha256'] ?? null;
        $size = $icon['sizeBytes'] ?? null;
        if (!is_string($id) || !self::uuid($id)
            || ($mime !== 'image/png' && $mime !== 'image/vnd.microsoft.icon')
            || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || !is_int($size) || $size < 1 || $size > BrandingFiles::MAX_BYTES) {
            return null;
        }
        return ['id' => $id, 'mime' => $mime, 'sha256' => $sha256, 'sizeBytes' => $size];
    }

    public function rename(array $input, string $actor, ClientContext $context): array
    {
        $rawName = $input['name'] ?? null;
        if (array_diff(array_keys($input), ['name']) !== [] || !is_string($rawName)) {
            throw new StorageError(422, 'invalid_brand_name');
        }
        $name = trim($rawName);
        if (!self::validName($name)) {
            throw new StorageError(422, 'invalid_brand_name');
        }
        $this->settings->putSystemSetting(self::NAME, $name, true, 'Installation display name', $actor, $context);
        return $this->read();
    }

    public static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di', $value) === 1;
    }

    private static function validName(string $name): bool
    {
        return mb_check_encoding($name, 'UTF-8') && mb_strlen($name) >= 1 && mb_strlen($name) <= 100
            && trim($name) === $name && preg_match('/[\x00-\x1f\x7f<>]/u', $name) !== 1;
    }
}
