<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\CompletePasswordReset;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class ConfirmPasswordResetAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CompletePasswordReset $reset,
        ResponseFactory $responses,
    ): ResponseInterface {
        $input = AuthInput::tokenAndPassword($request->getParsedBody());
        $completed = $reset->execute(
            $input['token'],
            $input['password'],
            ClientContext::fromRequest($request),
        );

        return $completed
            ? $responses->success(['completed' => true])
            : $responses->error(ApiMessage::AUTH_PASSWORD_RESET_INVALID, httpCode: Status::BAD_REQUEST);
    }
}
