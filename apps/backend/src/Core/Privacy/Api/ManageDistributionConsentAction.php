<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DistributionConsentService;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ManageDistributionConsentAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route,
        DistributionConsentService $consents, ResponseFactory $responses): ResponseInterface
    {
        $operation = (string) $route->getArgument('operation');
        $actor = AuthenticatedIdentity::fromRequest($request);
        try {
            if ($operation === 'withdraw') {
                $id = (string) $route->getArgument('id');
                if (!PrivacyRequestInput::uuid($id)) {
                    return $responses->notFound();
                }
                $consents->withdraw($actor, $id);
                return $responses->success(['withdrawn' => true]);
            }
            $body = $request->getParsedBody();
            if (!is_array($body) || !PrivacyRequestInput::keys($body, [
                'subjectPersonId','purposeCode','categoryCodes','fieldCodes',
                'conditionCodes','restrictionCodes','documentVersion','documentSha256','representationId'])) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            foreach (['categoryCodes','fieldCodes','conditionCodes','restrictionCodes'] as $key) {
                if (!is_array($body[$key] ?? null) || !array_is_list($body[$key])
                    || count(array_filter($body[$key], static fn($value) => PrivacyRequestInput::code($value))) !== count($body[$key])) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
            }
            if (!PrivacyRequestInput::uuid($body['subjectPersonId'] ?? null)
                || !PrivacyRequestInput::code($body['purposeCode'] ?? null)
                || !is_string($body['documentVersion'] ?? null)
                || !is_string($body['documentSha256'] ?? null)
                || (isset($body['representationId']) && !PrivacyRequestInput::uuid($body['representationId']))) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $id = $consents->grant($actor, $body['subjectPersonId'], $body['purposeCode'],
                $body['categoryCodes'], $body['fieldCodes'], $body['conditionCodes'],
                $body['restrictionCodes'], $body['documentVersion'], $body['documentSha256'],
                $body['representationId'] ?? null);
            return $responses->success(['id' => $id])->withStatus(Status::CREATED);
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
