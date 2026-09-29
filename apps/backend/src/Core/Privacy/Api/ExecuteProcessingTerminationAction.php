<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\ProcessingTerminationService;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ExecuteProcessingTerminationAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route,
        ProcessingTerminationService $termination, ResponseFactory $responses): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $body = $request->getParsedBody() ?? [];
        if (!PrivacyRequestInput::uuid($id) || ($body !== [] && !PrivacyRequestInput::keys($body, []))) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        try {
            return $responses->success($termination->execute(AuthenticatedIdentity::fromRequest($request), $id));
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
