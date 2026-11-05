<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\OffsetPagination;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\DataSubjectRequestFilter;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use DomainException;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ManagePrivacyRequestsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        DataSubjectRequestService $service,
        ResponseFactory $responses,
        ProcessingPurposeRegistry $purposes,
    ): ResponseInterface {
        $operation = (string) $route->getArgument('operation');
        if ($operation === 'options') {
            $codes = $purposes->codes();
            sort($codes, SORT_STRING);
            return $responses->success(['purposeCodes' => $codes]);
        }
        if ($operation === 'list') {
            $query = $request->getQueryParams();
            if (array_diff(array_keys($query), ['limit', 'offset', 'status', 'requestType', 'subjectPersonId', 'overdue']) !== []) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $status = $query['status'] ?? null;
            $type = $query['requestType'] ?? null;
            $subject = $query['subjectPersonId'] ?? null;
            $overdue = $query['overdue'] ?? null;
            if (($status !== null && (!is_string($status) || !in_array($status, ['submitted', 'identity_verification', 'in_review', 'in_progress', 'completed', 'partially_completed', 'rejected', 'cancelled'], true)))
                || ($type !== null && (!is_string($type) || !in_array($type, ['access', 'processing_information', 'correction', 'restriction', 'processing_termination', 'erasure', 'export', 'consent_withdrawal'], true)))
                || ($subject !== null && !PrivacyRequestInput::uuid($subject))
                || ($overdue !== null && !in_array($overdue, ['true', 'false'], true))) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $pagination = OffsetPagination::fromQuery($query);
            $filter = new DataSubjectRequestFilter(
                is_string($status) ? $status : null,
                is_string($type) ? $type : null,
                is_string($subject) ? $subject : null,
                $overdue === null ? null : $overdue === 'true',
            );
            return $responses->success($service->listManagement($pagination->limit, $pagination->offset, $filter)->toArray());
        }
        if ($operation === 'intake') {
            $body = $request->getParsedBody();
            if (!is_array($body) || !PrivacyRequestInput::keys($body, [
                'subjectPersonId', 'requestType', 'receivedAt', 'identityVerificationCode',
                'identityEvidenceReference', 'representativeVerificationReference', 'sourceChannel'])
                || !PrivacyRequestInput::uuid($body['subjectPersonId'] ?? null)
                || !is_string($body['requestType'] ?? null)
                || !PrivacyRequestInput::timestamp($body['receivedAt'] ?? null)
                || !PrivacyRequestInput::code($body['identityVerificationCode'] ?? null)
                || !is_string($body['identityEvidenceReference'] ?? null)
                || (isset($body['representativeVerificationReference'])
                    && !is_string($body['representativeVerificationReference']))
                || !is_string($body['sourceChannel'] ?? null)) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            /** @var array{subjectPersonId: string, requestType: string, receivedAt: string, identityVerificationCode: string, identityEvidenceReference: string, representativeVerificationReference?: string, sourceChannel: string} $body */
            try {
                $receivedAt = new DateTimeImmutable($body['receivedAt']);
            } catch (\Exception) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            try {
                $id = $service->intake(
                    AuthenticatedIdentity::fromRequest($request),
                    $body['subjectPersonId'],
                    $body['requestType'],
                    $receivedAt,
                    $body['identityVerificationCode'],
                    $body['identityEvidenceReference'],
                    $body['representativeVerificationReference'] ?? null,
                    $body['sourceChannel'],
                );
                return $responses->success(['id' => $id])->withStatus(Status::CREATED);
            } catch (DomainException) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
        }
        $id = (string) $route->getArgument('id');
        if (!PrivacyRequestInput::uuid($id)) {
            return $responses->notFound();
        }
        if ($operation === 'get') {
            $row = $service->getVisible(AuthenticatedIdentity::fromRequest($request), $id, true);
            return $row === null ? $responses->notFound() : $responses->success($row->toArray());
        }
        if ($service->getVisible(AuthenticatedIdentity::fromRequest($request), $id, true) === null) {
            return $responses->notFound();
        }
        $input = $request->getParsedBody();
        try {
            if (!is_array($input)) {
                return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            $toStatus = $input['toStatus'] ?? null;
            $reasonCode = $input['reasonCode'] ?? null;
            if ($operation === 'transition' && PrivacyRequestInput::keys($input, ['toStatus', 'reasonCode'])
                && is_string($toStatus) && PrivacyRequestInput::code($toStatus)
                && ($reasonCode === null || (is_string($reasonCode) && PrivacyRequestInput::code($reasonCode)))) {
                $service->transition(
                    AuthenticatedIdentity::fromRequest($request),
                    $id,
                    $toStatus,
                    $reasonCode,
                );
                return $responses->success(['id' => $id, 'status' => $toStatus]);
            }
            if ($operation === 'extend'
                && PrivacyRequestInput::keys($input, ['reasonCode'])
                && is_string($reasonCode) && PrivacyRequestInput::code($reasonCode)) {
                $deadlineCode = (string) $route->getArgument('deadlineCode');
                if (!PrivacyRequestInput::code($deadlineCode)) {
                    return $responses->notFound();
                }
                $due = $service->extendDeadline(
                    AuthenticatedIdentity::fromRequest($request),
                    $id,
                    $deadlineCode,
                    $reasonCode,
                );
                return $responses->success(['id' => $id, 'deadlineCode' => $deadlineCode, 'extendedDueAt' => $due->format(DATE_ATOM)]);
            }
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
    }
}
