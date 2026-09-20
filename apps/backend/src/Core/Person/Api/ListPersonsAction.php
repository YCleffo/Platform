<?php

declare(strict_types=1);

namespace App\Core\Person\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Person\Application\ListPersons;
use App\Core\Person\Application\PersonView;
use Psr\Http\Message\ResponseInterface;

final readonly class ListPersonsAction
{
    public function __invoke(
        ListPersons $listPersons,
        ResponseFactory $responseFactory,
    ): ResponseInterface {
        $persons = array_map(
            static fn(PersonView $person): array => $person->toArray(),
            $listPersons->execute(),
        );

        return $responseFactory->success($persons);
    }
}
