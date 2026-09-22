<?php

declare(strict_types=1);

namespace App\Core\Module\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Module\Application\ModuleService;
use App\Core\Settings\Api\ConfigurationInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class ModuleLifecycleAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ModuleService $modules,
        ResponseFactory $responses,
    ): ResponseInterface {
        $code = ConfigurationInput::moduleCode((string) $route->getArgument('code'));
        $operation = (string) $route->getArgument('operation');
        $identity = AuthenticatedIdentity::fromRequest($request);
        $change = $modules->setEnabled(
            $code,
            $operation === 'enable',
            $identity->accountId,
            ClientContext::fromRequest($request),
        );
        if ($change === null) {
            return $responses->notFound(ApiMessage::MODULE_NOT_FOUND);
        }

        return $responses->success([
            ...$change->after->toArray(),
            'changed' => $change->changed,
        ]);
    }
}
