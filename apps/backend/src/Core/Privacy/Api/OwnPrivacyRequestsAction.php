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
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        DataSubjectRequestService $service,
        ResponseFactory $responses,
    ): ResponseInterface {
        $actor = AuthenticatedIdentity::fromRequest($request);
        $operation = (string) $route->getArgument('operation');
        try {
            if ($operation === 'representations') {
                return $responses->success(['items' => $service->ownRepresentations($actor)]);
            }
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
                $query = $request->getQueryParams();
                $limit = filter_var($query['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
                $offset = filter_var($query['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100_000]]);
                if (!is_int($limit) || !is_int($offset)) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                return $responses->success(array_map(static fn($view): array => $view->toArray(), $service->listOwn($actor, $limit, $offset)));
            }
            if ($operation === 'get') {
                $id = (string) $route->getArgument('id');
                if (!PrivacyRequestInput::uuid($id)) {
                    return $responses->notFound();
                }
                $row = $service->getVisible($actor, $id);
                return $row === null ? $responses->notFound() : $responses->success($row->toArray());
            }
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->notFound();
    }
}
