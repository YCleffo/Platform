<?php

declare(strict_types=1);

namespace App\Core\Settings\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Settings\Application\FeatureFlag;
use App\Core\Settings\Application\FeatureFlagManager;
use App\Core\Settings\Application\SettingSecurityPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class FeatureFlagsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        FeatureFlagManager $flags,
        SettingSecurityPolicy $securityPolicy,
        ResponseFactory $responses,
    ): ResponseInterface {
        $operation = (string) $route->getArgument('operation');
        if ($operation === 'list') {
            return $responses->success(array_map(
                static fn(FeatureFlag $flag): array => $flag->toArray(),
                $flags->list(),
            ));
        }

        $code = ConfigurationInput::featureCode((string) $route->getArgument('code'));
        if ($operation === 'get') {
            $flag = $flags->get($code);
            return $flag === null
                ? $responses->notFound(ApiMessage::FEATURE_FLAG_NOT_FOUND)
                : $responses->success($flag->toArray());
        }

        $identity = AuthenticatedIdentity::fromRequest($request);
        $context = ClientContext::fromRequest($request);
        if ($operation === 'put') {
            $input = ConfigurationInput::featureFlag($request->getParsedBody(), $securityPolicy);
            $flag = $flags->put(
                $code,
                $input['moduleCode'],
                $input['enabled'],
                $input['rolloutPercent'],
                $input['config'],
                $input['description'],
                $identity->accountId,
                $context,
            );
            return $flag === null
                ? $responses->notFound(ApiMessage::MODULE_NOT_FOUND)
                : $responses->success($flag->toArray());
        }

        $deleted = $flags->delete($code, $identity->accountId, $context);
        return $deleted === null
            ? $responses->notFound(ApiMessage::FEATURE_FLAG_NOT_FOUND)
            : $responses->success(['deleted' => true, 'code' => $deleted->code]);
    }
}
