<?php

declare(strict_types=1);

namespace App\Core\Module\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Module\Application\ModuleService;
use App\Core\Module\Application\ModuleView;
use Psr\Http\Message\ResponseInterface;

final readonly class ListModulesAction
{
    public function __invoke(ModuleService $modules, ResponseFactory $responses): ResponseInterface
    {
        return $responses->success(array_map(
            static fn(ModuleView $module): array => $module->toArray(),
            $modules->list(),
        ));
    }
}
