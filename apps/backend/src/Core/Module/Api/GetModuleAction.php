<?php

declare(strict_types=1);

namespace App\Core\Module\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Module\Application\ModuleService;
use App\Core\Settings\Api\ConfigurationInput;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class GetModuleAction
{
    public function __invoke(
        CurrentRoute $route,
        ModuleService $modules,
        ResponseFactory $responses,
    ): ResponseInterface {
        $code = ConfigurationInput::moduleCode((string) $route->getArgument('code'));
        $module = $modules->get($code);

        return $module === null
            ? $responses->notFound(ApiMessage::MODULE_NOT_FOUND)
            : $responses->success($module->toArray());
    }
}
