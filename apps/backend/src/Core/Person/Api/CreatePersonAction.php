<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Person\Application\CreatePerson;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class CreatePersonAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CreatePerson $createPerson,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $person = $createPerson->execute(
            PersonInput::createPerson($request->getParsedBody()),
        );

        return $responseFactory
            ->success($person->toArray())
            ->withStatus(Status::CREATED);
    }
}
