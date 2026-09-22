<?php

declare(strict_types=1);

namespace App\Core\Rbac\Api;

use App\Api\Shared\OffsetPagination;
use App\Api\Shared\ResponseFactory;
use App\Core\Rbac\Application\ListPermissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListPermissionsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ListPermissions $listPermissions,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $pagination = OffsetPagination::fromQuery($request->getQueryParams());
        return $responseFactory->success(
            $listPermissions->execute($pagination->limit, $pagination->offset)->toArray(),
        );
    }
}
