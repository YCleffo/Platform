<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\ProcessingBlockService;
use DateTimeImmutable;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ManageProcessingBlocksAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        DataSubjectRequestService $requests,
        ProcessingBlockService $blocks,
        ResponseFactory $responses,
    ): ResponseInterface {
        $operation = (string) $route->getArgument('operation');
        $actor = AuthenticatedIdentity::fromRequest($request);
        if ($operation === 'release') {
            $id = (string) $route->getArgument('id');
            $input = $request->getParsedBody();
            if (!PrivacyRequestInput::uuid($id) || !is_array($input)
                || !PrivacyRequestInput::keys($input, ['reasonCode'])) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $reason = $input['reasonCode'] ?? null;
            if (!is_string($reason) || !PrivacyRequestInput::code($reason)) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            try {
                $changed = $blocks->releaseBlock($actor, $id, $reason);
                return $responses->success(['id' => $id, 'released' => true, 'changed' => $changed]);
            } catch (DomainException) {
                return $responses->notFound();
            }
        }
        $requestId = (string) $route->getArgument('id');
        if (!PrivacyRequestInput::uuid($requestId)) {
            return $responses->notFound();
        }
        $requestRow = $requests->getVisible($actor, $requestId, true);
        if ($requestRow === null) {
            return $responses->notFound();
        }
        $subject = $requestRow->subjectPersonId;
        if ($operation === 'list') {
            return $responses->success(array_values(array_filter(
                $blocks->listActiveForSubject($subject),
                static fn(array $row): bool => $row['source_request_id'] === $requestId,
            )));
        }
        $input = $request->getParsedBody();
        if (!is_array($input) || !PrivacyRequestInput::keys(
            $input,
            ['scopeType', 'purposeCode', 'resourceType', 'resourceId', 'reasonCode', 'expiresAt'],
        )) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        $scope = $input['scopeType'] ?? null;
        $purpose = $input['purposeCode'] ?? null;
        $resourceType = $input['resourceType'] ?? null;
        $resourceId = $input['resourceId'] ?? null;
        $reason = $input['reasonCode'] ?? null;
        $expires = $input['expiresAt'] ?? null;
        if (!is_string($scope) || !PrivacyRequestInput::code($scope)
            || !is_string($reason) || !PrivacyRequestInput::code($reason)
            || ($purpose !== null && (!is_string($purpose) || !PrivacyRequestInput::code($purpose)))
            || ($resourceType !== null && (!is_string($resourceType) || !PrivacyRequestInput::code($resourceType)))
            || ($resourceId !== null && (!is_string($resourceId) || !PrivacyRequestInput::uuid($resourceId)))
            || ($expires !== null && (!is_string($expires) || !PrivacyRequestInput::timestamp($expires)))) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        try {
            $id = $blocks->createBlock(
                $actor,
                $subject,
                $scope,
                $purpose,
                $resourceType,
                $resourceId,
                $reason,
                $requestId,
                $expires !== null ? new DateTimeImmutable($expires) : null,
            );
            return $responses->success(['id' => $id])->withStatus(Status::CREATED);
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
