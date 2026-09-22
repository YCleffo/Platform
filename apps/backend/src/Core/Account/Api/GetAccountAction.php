<?php

declare(strict_types=1);

namespace App\Core\Account\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Account\Application\GetAccount;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class GetAccountAction
{
    public function __invoke(
        CurrentRoute $route,
        GetAccount $getAccount,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!Uuid::isValid($id)) {
            return $responseFactory->error(ApiMessage::ACCOUNT_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }

        $account = $getAccount->execute($id);
        return $account === null
            ? $responseFactory->notFound(ApiMessage::ACCOUNT_NOT_FOUND)
            : $responseFactory->success($account->toArray());
    }
}
