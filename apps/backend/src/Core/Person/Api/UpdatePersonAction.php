<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Person\Application\UpdatePerson;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class UpdatePersonAction
{
    public function __invoke(
        CurrentRoute $route,
        ServerRequestInterface $request,
        UpdatePerson $updatePerson,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!Uuid::isValid($id)) {
            return $responseFactory->error(ApiMessage::PERSON_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }

        $person = $updatePerson->execute(
            PersonInput::updatePerson($id, $request->getParsedBody()),
        );

        return $person === null
            ? $responseFactory->notFound(ApiMessage::PERSON_ACTIVE_NOT_FOUND)
            : $responseFactory->success($person->toArray());
    }
}
