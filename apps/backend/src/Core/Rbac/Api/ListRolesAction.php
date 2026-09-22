<?php

declare(strict_types=1);

namespace App\Core\Rbac\Api;

use App\Api\Shared\OffsetPagination;
use App\Api\Shared\ResponseFactory;
use App\Core\Rbac\Application\ListRoles;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListRolesAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ListRoles $listRoles,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $pagination = OffsetPagination::fromQuery($request->getQueryParams());
        return $responseFactory->success(
            $listRoles->execute($pagination->limit, $pagination->offset)->toArray(),
        );
    }
}
