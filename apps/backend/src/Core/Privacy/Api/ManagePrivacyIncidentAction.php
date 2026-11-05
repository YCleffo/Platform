<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\PrivacyIncidentService;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ManagePrivacyIncidentAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        PrivacyIncidentService $incidents,
        ResponseFactory $responses,
    ): ResponseInterface {
        $operation = (string) $route->getArgument('operation');
        $id = (string) $route->getArgument('id');
        if ($operation !== 'detect' && !PrivacyRequestInput::uuid($id)) {
            return $responses->notFound();
        }
        try {
            if ($operation === 'get') {
                return $responses->success($incidents->export($id));
            }
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $actor = AuthenticatedIdentity::fromRequest($request);
            if ($operation === 'detect') {
                $categories = $body['categoryCodes'] ?? null;
                $subjects = $body['subjectPersonIds'] ?? [];
                if (!PrivacyRequestInput::keys($body, ['incidentType','reasonCode','categoryCodes','estimatedSubjects','subjectPersonIds'])
                    || !PrivacyRequestInput::code($body['incidentType'] ?? null)
                    || !PrivacyRequestInput::code($body['reasonCode'] ?? null)
                    || !is_array($categories) || !array_is_list($categories)
                    || !is_array($subjects) || !array_is_list($subjects)
                    || count(array_filter($categories, static fn($value) => PrivacyRequestInput::code($value))) !== count($categories)
                    || count(array_filter($subjects, static fn($value) => PrivacyRequestInput::uuid($value))) !== count($subjects)
                    || (isset($body['estimatedSubjects']) && (!is_int($body['estimatedSubjects']) || $body['estimatedSubjects'] < 0))) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                /** @var array{incidentType: string, reasonCode: string, categoryCodes: list<string>, estimatedSubjects?: int, subjectPersonIds?: list<string>} $body */
                $categories = $body['categoryCodes'];
                $subjects = $body['subjectPersonIds'] ?? [];
                $newId = $incidents->detect(
                    $actor,
                    $body['incidentType'],
                    $body['reasonCode'],
                    $categories,
                    $body['estimatedSubjects'] ?? null,
                    $subjects,
                );
                return $responses->success(['id' => $newId])->withStatus(Status::CREATED);
            }
            if ($operation === 'transition') {
                if (!PrivacyRequestInput::keys($body, ['status','reasonCode'])
                    || !is_string($body['status'] ?? null)
                    || !PrivacyRequestInput::code($body['reasonCode'] ?? null)) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                /** @var array{status: string, reasonCode: string} $body */
                $incidents->transition($actor, $id, $body['status'], $body['reasonCode']);
            } elseif ($operation === 'notification') {
                if (!PrivacyRequestInput::keys($body, ['kind','referenceCode'])
                    || !is_string($body['kind'] ?? null)
                    || !PrivacyRequestInput::code($body['referenceCode'] ?? null)) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                /** @var array{kind: string, referenceCode: string} $body */
                $incidents->recordExternalNotification($actor, $id, $body['kind'], $body['referenceCode']);
            } else {
                return $responses->notFound();
            }
            return $responses->success($incidents->export($id));
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
