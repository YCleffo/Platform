<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Person\Application\CreatePersonContact;
use App\Core\Shared\Application\ConflictException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class CreatePersonContactAction
{
    public function __invoke(
        CurrentRoute $route,
        ServerRequestInterface $request,
        CreatePersonContact $createContact,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!Uuid::isValid($id)) {
            return $responseFactory->error(ApiMessage::PERSON_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }

        try {
            $contact = $createContact->execute(
                PersonInput::createContact($id, $request->getParsedBody()),
            );
        } catch (ConflictException) {
            return $responseFactory->error(
                ApiMessage::PERSON_CONTACT_CONFLICT,
                httpCode: Status::CONFLICT,
            );
        }

        return $contact === null
            ? $responseFactory->notFound(ApiMessage::PERSON_NOT_FOUND)
            : $responseFactory
                ->success($contact->toArray())
                ->withStatus(Status::CREATED);
    }
}
