<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\OffsetPagination;
use App\Api\Shared\ResponseFactory;
use App\Core\Person\Application\ListPersons;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListPersonsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ListPersons $listPersons,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $pagination = OffsetPagination::fromQuery($request->getQueryParams());

        return $responseFactory->success(
            $listPersons->execute($pagination->limit, $pagination->offset)->toArray(),
        );
    }
}
