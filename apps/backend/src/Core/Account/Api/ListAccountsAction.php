<?php

declare(strict_types=1);

namespace App\Core\Account\Api;

use App\Api\Shared\OffsetPagination;
use App\Api\Shared\ResponseFactory;
use App\Core\Account\Application\ListAccounts;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListAccountsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ListAccounts $listAccounts,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $pagination = OffsetPagination::fromQuery($request->getQueryParams());
        return $responseFactory->success(
            $listAccounts->execute($pagination->limit, $pagination->offset)->toArray(),
        );
    }
}
