<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DestructionEvidenceExporter;
use App\Core\Privacy\Application\ErasureCaseService;
use App\Core\Privacy\Application\ErasureExecutor;
use App\Core\Privacy\Application\LegalDestructionPackageService;
use DateTimeImmutable;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ManageErasureAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route,
        ErasureCaseService $cases, ErasureExecutor $executor,
        DestructionEvidenceExporter $exporter, LegalDestructionPackageService $legalPackages,
        ResponseFactory $responses): ResponseInterface
    {
        $operation = (string) $route->getArgument('operation');
        $id = (string) $route->getArgument('id');
        if (in_array($operation, ['get', 'execute', 'evidence', 'attest'], true) && !PrivacyRequestInput::uuid($id)) {
            return $responses->notFound();
        }
        try {
            if ($operation === 'get') {
                $row = $cases->get($id);
                return $row === null ? $responses->notFound() : $responses->success($row);
            }
            if ($operation === 'evidence') {
                $query = $request->getQueryParams();
                if ($query !== [] && !PrivacyRequestInput::keys($query, ['limit', 'offset'])) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                $limit = $query['limit'] ?? '100';
                $offset = $query['offset'] ?? '0';
                if (!is_string($limit) || !is_string($offset)
                    || preg_match('/^[1-9][0-9]{0,3}$/D', $limit) !== 1
                    || preg_match('/^(0|[1-9][0-9]{0,8})$/D', $offset) !== 1) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                return $responses->success($exporter->export($id, (int) $limit, (int) $offset));
            }
            $body = $request->getParsedBody() ?? [];
            if ($operation === 'attest') {
                if (!PrivacyRequestInput::keys($body, ['evidenceId', 'signedDocumentReference', 'signatureReference'])
                    || !PrivacyRequestInput::uuid($body['evidenceId'] ?? null)
                    || !is_string($body['signedDocumentReference'] ?? null)
                    || !is_string($body['signatureReference'] ?? null)) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                $legalPackages->attest($id, $body['evidenceId'],
                    AuthenticatedIdentity::fromRequest($request)->accountId,
                    $body['signedDocumentReference'], $body['signatureReference']);
                return $responses->success(['attested' => true]);
            }
            if ($operation === 'execute') {
                if ($body !== [] && !PrivacyRequestInput::keys($body, ['limit'])) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                $limit = $body['limit'] ?? 25;
                if (!is_int($limit) || $limit < 1 || $limit > 100) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                return $responses->success($executor->executeBatch(AuthenticatedIdentity::fromRequest($request),
                    $id, $limit));
            }
            if (!PrivacyRequestInput::keys($body,
                ['subjectPersonId', 'reasonCode', 'sourceRequestId', 'deadlineAt', 'deadlineCalendarType'])
                || !PrivacyRequestInput::uuid($body['subjectPersonId'] ?? null)
                || !PrivacyRequestInput::code($body['reasonCode'] ?? null)
                || (isset($body['sourceRequestId']) && !PrivacyRequestInput::uuid($body['sourceRequestId']))
                || (isset($body['deadlineAt']) && !PrivacyRequestInput::timestamp($body['deadlineAt']))
                || (isset($body['deadlineCalendarType']) && !in_array($body['deadlineCalendarType'],
                    ['calendar_days', 'working_days', 'hours'], true))) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $caseId = $cases->create(AuthenticatedIdentity::fromRequest($request), $body['subjectPersonId'],
                $body['reasonCode'], $body['sourceRequestId'] ?? null,
                isset($body['deadlineAt']) ? new DateTimeImmutable($body['deadlineAt']) : null,
                $body['deadlineCalendarType'] ?? null);
            return $responses->success(['id' => $caseId])->withStatus(Status::CREATED);
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
