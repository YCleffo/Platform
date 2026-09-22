<?php

declare(strict_types=1);

namespace App\Core\Account\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Account\Application\CreateAccount;
use App\Core\Shared\Application\ConflictException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class CreateAccountAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CreateAccount $createAccount,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        try {
            $account = $createAccount->execute(
                AccountInput::create($request->getParsedBody()),
            );
        } catch (ConflictException) {
            return $responseFactory->error(
                ApiMessage::ACCOUNT_CONFLICT,
                httpCode: Status::CONFLICT,
            );
        }

        return $account === null
            ? $responseFactory->notFound(ApiMessage::ACCOUNT_PERSON_NOT_FOUND)
            : $responseFactory
                ->success($account->toArray())
                ->withStatus(Status::CREATED);
    }
}
