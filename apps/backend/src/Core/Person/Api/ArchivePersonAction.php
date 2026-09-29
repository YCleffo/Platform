<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Person\Application\ArchivePerson;
use App\Core\Shared\Application\ConflictException;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ArchivePersonAction
{
    public function __invoke(
        CurrentRoute $route,
        ArchivePerson $archivePerson,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!Uuid::isValid($id)) {
            return $responseFactory->error(ApiMessage::PERSON_INVALID_ID, httpCode: Status::BAD_REQUEST);
        }

        try {
            $person = $archivePerson->execute($id);
        } catch (ConflictException) {
            return $responseFactory->error(ApiMessage::ACCOUNT_CONFLICT, httpCode: Status::CONFLICT);
        }
        return $person === null
            ? $responseFactory->notFound(ApiMessage::PERSON_NOT_FOUND)
            : $responseFactory->success($person->toArray());
    }
}
