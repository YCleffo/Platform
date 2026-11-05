<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\ProcessingInformationService;
use DomainException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class ProcessingInformationAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ProcessingInformationService $service,
        ResponseFactoryInterface $psrResponses,
        StreamFactoryInterface $streams,
        ResponseFactory $responses,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        $representationId = $route->getArgument('representationId');
        if (!PrivacyRequestInput::uuid($id)
            || ($representationId !== null && !PrivacyRequestInput::uuid($representationId))) {
            return $responses->notFound();
        }
        try {
            $operator = $route->getArgument('operator');
            $json = $service->issue(
                AuthenticatedIdentity::fromRequest($request),
                $id,
                $representationId,
                $operator === 'intake',
            );
            return $psrResponses->createResponse(200)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withBody($streams->createStream($json));
        } catch (DomainException) {
            return $responses->notFound();
        }
    }
}
