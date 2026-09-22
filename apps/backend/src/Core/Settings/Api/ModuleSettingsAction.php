<?php

declare(strict_types=1);

namespace App\Core\Settings\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Settings\Application\ModuleSetting;
use App\Core\Settings\Application\SettingSecurityPolicy;
use App\Core\Settings\Application\SettingsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class ModuleSettingsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        SettingsService $settings,
        SettingSecurityPolicy $securityPolicy,
        ResponseFactory $responses,
    ): ResponseInterface {
        $operation = (string) $route->getArgument('operation');
        $moduleCode = ConfigurationInput::moduleCode((string) $route->getArgument('moduleCode'));
        if ($operation === 'list') {
            $items = $settings->listModuleSettings($moduleCode);
            return $items === null
                ? $responses->notFound(ApiMessage::MODULE_NOT_FOUND)
                : $responses->success(array_map(
                    static fn(ModuleSetting $setting): array => $setting->toArray(),
                    $items,
                ));
        }

        $key = ConfigurationInput::settingKey((string) $route->getArgument('key'));
        if (!$settings->moduleExists($moduleCode)) {
            return $responses->notFound(ApiMessage::MODULE_NOT_FOUND);
        }
        if ($operation === 'get') {
            $setting = $settings->findModuleSetting($moduleCode, $key);
            return $setting === null
                ? $responses->notFound(ApiMessage::SETTING_NOT_FOUND)
                : $responses->success($setting->toArray());
        }

        $identity = AuthenticatedIdentity::fromRequest($request);
        $context = ClientContext::fromRequest($request);
        if ($operation === 'put') {
            $input = ConfigurationInput::moduleSetting($request->getParsedBody(), $key, $securityPolicy);
            $setting = $settings->putModuleSetting(
                $moduleCode,
                $key,
                $input['value'],
                $input['description'],
                $identity->accountId,
                $context,
            );
            return $setting === null
                ? $responses->notFound(ApiMessage::MODULE_NOT_FOUND)
                : $responses->success($setting->toArray());
        }

        $deleted = $settings->deleteModuleSetting($moduleCode, $key, $identity->accountId, $context);
        return $deleted === null
            ? $responses->notFound(ApiMessage::SETTING_NOT_FOUND)
            : $responses->success(['deleted' => true, 'key' => $deleted->key]);
    }
}
