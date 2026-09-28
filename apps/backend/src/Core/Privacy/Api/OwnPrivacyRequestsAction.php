<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class OwnPrivacyRequestsAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route,
        DataSubjectRequestService $service, ResponseFactory $responses): ResponseInterface
    {
        $actor = AuthenticatedIdentity::fromRequest($request);
        $operation = (string) $route->getArgument('operation');
        try {
            if ($operation === 'submit') {
                $input = $request->getParsedBody();
                if (!is_array($input) || !PrivacyRequestInput::keys($input, ['requestType'])) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                $type = $input['requestType'] ?? null;
                if (!is_string($type) || !PrivacyRequestInput::code($type)) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                $id = $service->submitSelf($actor, $type);
                return $responses->success(['id' => $id])->withStatus(Status::CREATED);
            }
            if ($operation === 'submit_representative') {
                $representation = (string) $route->getArgument('representationId');
                $input = $request->getParsedBody();
                if (!PrivacyRequestInput::uuid($representation) || !is_array($input)
                    || !PrivacyRequestInput::keys($input, ['subjectPersonId', 'requestType'])) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                $subject = $input['subjectPersonId'] ?? null;
                $type = $input['requestType'] ?? null;
                if (!is_string($subject) || !PrivacyRequestInput::uuid($subject)
                    || !is_string($type) || !PrivacyRequestInput::code($type)) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                $id = $service->submitForSubject($actor, $subject, $representation, $type);
                return $responses->success(['id' => $id])->withStatus(Status::CREATED);
            }
            if ($operation === 'list') {
                return $responses->success($service->listOwn($actor));
            }
            if ($operation === 'get') {
                $id = (string) $route->getArgument('id');
                if (!PrivacyRequestInput::uuid($id)) {
                    return $responses->notFound();
                }
                $row = $service->getVisible($actor, $id);
                return $row === null ? $responses->notFound() : $responses->success($row);
            }
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->notFound();
    }
}
