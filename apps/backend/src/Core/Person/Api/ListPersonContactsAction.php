<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Person\Application\ListPersonContacts;
use App\Core\Person\Application\PersonContactView;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ListPersonContactsAction
{
    public function __invoke(
        CurrentRoute $route,
        ListPersonContacts $listContacts,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!Uuid::isValid($id)) {
            return $responseFactory->error(ApiMessage::PERSON_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }

        $contacts = $listContacts->execute($id);
        if ($contacts === null) {
            return $responseFactory->notFound(ApiMessage::PERSON_NOT_FOUND);
        }

        return $responseFactory->success(array_map(
            static fn(PersonContactView $contact): array => $contact->toArray(),
            $contacts,
        ));
    }
}
