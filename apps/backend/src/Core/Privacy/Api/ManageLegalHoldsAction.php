<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\LegalHoldService;
use DateTimeImmutable;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ManageLegalHoldsAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route,
        LegalHoldService $holds, ResponseFactory $responses): ResponseInterface
    {
        $operation = (string) $route->getArgument('operation');
        $actor = AuthenticatedIdentity::fromRequest($request);
        $id = (string) $route->getArgument('id');
        if ($operation === 'get') {
            return PrivacyRequestInput::uuid($id) && ($row = $holds->get($id)) !== null
                ? $responses->success($row) : $responses->notFound();
        }
        if ($operation === 'list') {
            $query = $request->getQueryParams();
            if (($query !== [] && !PrivacyRequestInput::keys($query, ['subjectPersonId']))
                || (isset($query['subjectPersonId']) && !PrivacyRequestInput::uuid($query['subjectPersonId']))) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            /** @var array{subjectPersonId?: string} $query */
            return $responses->success($holds->list($query['subjectPersonId'] ?? null));
        }
        $input = $request->getParsedBody();
        if ($operation === 'release') {
            if (!PrivacyRequestInput::uuid($id) || !PrivacyRequestInput::keys($input, ['reasonCode'])
                || !PrivacyRequestInput::code($input['reasonCode'] ?? null)) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            /** @var array{reasonCode: string} $input */
            try {
                $changed = $holds->release($actor, $id, $input['reasonCode']);
                return $responses->success(['id' => $id, 'released' => true, 'changed' => $changed]);
            } catch (DomainException) {
                return $responses->notFound();
            }
        }
        if (!PrivacyRequestInput::keys($input, ['subjectPersonId', 'scopeType', 'purposeCode',
            'resourceType', 'resourceId', 'reasonCode', 'sourceType', 'sourceReference', 'expiresAt'])
            || !is_string($input['scopeType'] ?? null) || !PrivacyRequestInput::code($input['reasonCode'] ?? null)) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        foreach (['subjectPersonId', 'resourceId'] as $key) {
            if (isset($input[$key]) && !PrivacyRequestInput::uuid($input[$key])) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
        }
        foreach (['purposeCode', 'resourceType', 'sourceType'] as $key) {
            if (isset($input[$key]) && !PrivacyRequestInput::code($input[$key])) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
        }
        if (isset($input['expiresAt']) && !PrivacyRequestInput::timestamp($input['expiresAt'])) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        if (isset($input['sourceReference']) && (!is_string($input['sourceReference'])
            || strlen($input['sourceReference']) > 160
            || preg_match('/^[a-zA-Z0-9._:\/-]+$/D', $input['sourceReference']) !== 1)) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        /** @var array{scopeType: string, reasonCode: string, subjectPersonId?: string, purposeCode?: string, resourceType?: string, resourceId?: string, sourceType?: string, sourceReference?: string, expiresAt?: string} $input */
        try {
            $createdId = $holds->create($actor, $input['subjectPersonId'] ?? null, $input['scopeType'],
                $input['purposeCode'] ?? null, $input['resourceType'] ?? null, $input['resourceId'] ?? null,
                $input['reasonCode'], $input['sourceType'] ?? null, $input['sourceReference'] ?? null,
                isset($input['expiresAt']) ? new DateTimeImmutable($input['expiresAt']) : null);
            return $responses->success(['id' => $createdId])->withStatus(Status::CREATED);
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
