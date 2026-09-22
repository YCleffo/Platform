<?php

declare(strict_types=1);

namespace App\Core\Settings\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Settings\Application\SettingSecurityPolicy;
use App\Core\Settings\Application\SettingsService;
use App\Core\Settings\Application\SystemSetting;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class SystemSettingsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        SettingsService $settings,
        SettingSecurityPolicy $securityPolicy,
        ResponseFactory $responses,
    ): ResponseInterface {
        $operation = (string) $route->getArgument('operation');
        if ($operation === 'list') {
            return $responses->success(array_map(
                static fn(SystemSetting $setting): array => $setting->toArray(),
                $settings->listSystemSettings(),
            ));
        }

        $key = ConfigurationInput::settingKey((string) $route->getArgument('key'));
        if ($operation === 'get') {
            $setting = $settings->findSystemSetting($key);
            return $setting === null
                ? $responses->notFound(ApiMessage::SETTING_NOT_FOUND)
                : $responses->success($setting->toArray());
        }

        $identity = AuthenticatedIdentity::fromRequest($request);
        $context = ClientContext::fromRequest($request);
        if ($operation === 'put') {
            $input = ConfigurationInput::systemSetting($request->getParsedBody(), $key, $securityPolicy);
            return $responses->success($settings->putSystemSetting(
                $key,
                $input['value'],
                $input['isPublic'],
                $input['description'],
                $identity->accountId,
                $context,
            )->toArray());
        }

        $deleted = $settings->deleteSystemSetting($key, $identity->accountId, $context);
        return $deleted === null
            ? $responses->notFound(ApiMessage::SETTING_NOT_FOUND)
            : $responses->success(['deleted' => true, 'key' => $deleted->key]);
    }
}
