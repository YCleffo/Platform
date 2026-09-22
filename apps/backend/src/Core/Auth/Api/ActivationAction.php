<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\CompleteAccountActivation;
use App\Core\Client\Domain\ClientContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class ActivationAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CompleteAccountActivation $activation,
        ResponseFactory $responses,
    ): ResponseInterface {
        $input = AuthInput::tokenAndPassword($request->getParsedBody());
        $completed = $activation->execute(
            $input['token'],
            $input['password'],
            ClientContext::fromRequest($request),
        );

        return $completed
            ? $responses->success(['activated' => true])
            : $responses->error(ApiMessage::AUTH_ACTIVATION_INVALID, httpCode: Status::BAD_REQUEST);
    }
}
