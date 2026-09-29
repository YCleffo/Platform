<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\PersonalDataExportLimitException;
use App\Core\Privacy\Application\PersonalDataExportService;
use DomainException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class PersonalDataExportAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route,
        PersonalDataExportService $service, ResponseFactoryInterface $psrResponses,
        StreamFactoryInterface $streams, ResponseFactory $responses): ResponseInterface
    {
        $id = (string) $route->getArgument('id');
        $representationId = $route->getArgument('representationId');
        if (!PrivacyRequestInput::uuid($id)
            || ($representationId !== null && !PrivacyRequestInput::uuid($representationId))) {
            return $responses->notFound();
        }
        try {
            $actor = AuthenticatedIdentity::fromRequest($request);
            $result = $route->getArgument('operator') === true
                ? $service->exportOperator($actor, $id)
                : ($representationId === null
                ? $service->exportSelf($actor, $id)
                : $service->exportRepresentative($actor, $id, $representationId));
            return $psrResponses->createResponse(200)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withBody($streams->createStream($result->json));
        } catch (PersonalDataExportLimitException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: 413);
        } catch (DomainException) {
            return $responses->notFound();
        }
    }
}
