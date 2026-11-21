<?php

declare(strict_types=1);

namespace App\Tests\Foundation;

use App\Branding\BrandingSettings;
use App\Core\Client\Application\ClientContextResolver;
use App\Core\Settings\Application\SettingsService;
use App\Storage\Application\StorageError;
use App\Tests\Storage02\IsolatedS3Runtime;
use HttpSoft\Message\ServerRequest;

require_once __DIR__ . '/HomeworkFixture.php';

final class BrandingSettingsTest extends HomeworkFixture
{
    public function testRealS3FaviconPublicationReceiptAndRemoval(): void
    {
        $config = $this->container->get(\App\Storage\Infrastructure\S3Config::class);
        IsolatedS3Runtime::assertConfigured($config);
        $objects = $this->container->get(\App\Storage\Infrastructure\S3Objects::class);
        $files = $this->container->get(\App\Branding\BrandingFiles::class);
        $context = $this->container->get(ClientContextResolver::class)->resolve(new ServerRequest());
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aAYsAAAAASUVORK5CYII=', true);
        $pending = $files->start(
            ['sizeBytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)],
            $this->teacher->accountId,
            $context,
        );
        try {
            $internal = parse_url($config->internalEndpoint);
            $public = parse_url($pending['request']['url']);
            $curl = curl_init($pending['request']['url']);
            $headers = [];
            foreach ((array) $pending['request']['headers'] as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $bytes,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECT_TO => [$public['host'] . ':' . ($public['port'] ?? 80) . ':'
                    . $internal['host'] . ':' . ($internal['port'] ?? 80)]]);
            curl_exec($curl);
            self::assertSame(200, curl_getinfo($curl, CURLINFO_RESPONSE_CODE));
            curl_close($curl);
            $result = $files->complete($pending['id'], $this->teacher->accountId, $context);
            self::assertSame($result, $files->complete($pending['id'], $this->teacher->accountId, $context));
            self::assertSame('image/png', $result['favicon']['mime']);
            $download = $files->open($pending['id']);
            try {
                self::assertSame($bytes, stream_get_contents($download['stream']));
            } finally {
                fclose($download['stream']);
            }
            self::assertNull($files->remove($this->teacher->accountId, $context)['favicon']);
            try {
                $files->open($pending['id']);
                self::fail('A removed icon is still public.');
            } catch (StorageError $error) {
                self::assertSame(404, $error->status);
            }
        } finally {
            $objects->delete($config->bucketStorage, 'staging/' . $pending['id'] . '/branding');
            $objects->delete($config->bucketStorage, 'branding/' . $pending['id']);
        }
    }

    public function testInstallationNamePersistsAsAuditedPublicConfiguration(): void
    {
        $branding = $this->container->get(BrandingSettings::class);
        $context = $this->container->get(ClientContextResolver::class)->resolve(new ServerRequest());
        $saved = $branding->rename(['name' => '  School Platform  '], $this->teacher->accountId, $context);
        self::assertSame('School Platform', $saved['name']);
        self::assertSame($saved, $branding->read());
        $setting = $this->container->get(SettingsService::class)->findSystemSetting('platform.name');
        self::assertNotNull($setting);
        self::assertTrue($setting->isPublic);
        self::assertSame('School Platform', $setting->value);
    }

    public function testNameValidationRejectsMarkupControlCharactersAndUnboundedNames(): void
    {
        $branding = $this->container->get(BrandingSettings::class);
        $context = $this->container->get(ClientContextResolver::class)->resolve(new ServerRequest());
        foreach (['', ' ', '<script>', "School\nPlatform", str_repeat('a', 101)] as $name) {
            try {
                $branding->rename(['name' => $name], $this->teacher->accountId, $context);
                self::fail('Invalid installation name was accepted.');
            } catch (StorageError $error) {
                self::assertSame(422, $error->status);
            }
        }
    }

    public function testPublicFaviconProjectionCannotPublishAnExternalUrlOrArbitraryObjectKey(): void
    {
        $settings = $this->container->get(SettingsService::class);
        $context = $this->container->get(ClientContextResolver::class)->resolve(new ServerRequest());
        $settings->putSystemSetting(
            'platform.favicon',
            ['id' => 'https://outside.example/private', 'mime' => 'image/png'],
            true,
            null,
            $this->teacher->accountId,
            $context,
        );
        self::assertNull($this->container->get(BrandingSettings::class)->read()['favicon']);
    }

    public function testBrandingRoutesApplySettingsPermissionToEveryMutation(): void
    {
        foreach (require dirname(__DIR__, 2) . '/config/common/branding-routes.php' as $route) {
            if ($route->getData('methods') === ['GET']) {
                continue;
            }
            $middleware = $route->getData('enabledMiddlewares');
            self::assertContains(\App\Core\Auth\Infrastructure\RequireAuthentication::class, $middleware);
            $permissions = array_filter($middleware, static fn(mixed $item): bool => is_array($item)
                && ($item['class'] ?? null) === \App\Core\Rbac\Infrastructure\RequirePermission::class);
            self::assertCount(1, $permissions);
            self::assertSame('core.settings.manage', array_values($permissions)[0]['__construct()']['permission']);
        }
    }
}
