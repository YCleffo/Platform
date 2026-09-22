<?php

declare(strict_types=1);

namespace App\Core\Settings\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Settings\Application\SettingsService;
use Psr\Http\Message\ResponseInterface;

final readonly class PublicConfigAction
{
    public function __invoke(SettingsService $settings, ResponseFactory $responses): ResponseInterface
    {
        $public = [];
        foreach ($settings->getPublicSystemSettings() as $setting) {
            $public[$setting->key] = $setting->value;
        }

        return $responses->success(['settings' => (object) $public]);
    }
}
