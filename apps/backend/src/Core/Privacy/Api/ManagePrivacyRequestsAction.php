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

final readonly class ManagePrivacyRequestsAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route,
        DataSubjectRequestService $service, ResponseFactory $responses): ResponseInterface
    {
        $operation = (string) $route->getArgument('operation');
        if ($operation === 'list') {
            return $responses->success($service->listManagement());
        }
        $id = (string) $route->getArgument('id');
        if (!PrivacyRequestInput::uuid($id)) {
            return $responses->notFound();
        }
        if ($operation === 'get') {
            $row = $service->getVisible(AuthenticatedIdentity::fromRequest($request), $id, true);
            return $row === null ? $responses->notFound() : $responses->success($row);
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
                $service->transition(AuthenticatedIdentity::fromRequest($request), $id,
                    $toStatus, $reasonCode);
                return $responses->success(['id' => $id, 'status' => $toStatus]);
            }
            if ($operation === 'extend'
                && PrivacyRequestInput::keys($input, ['reasonCode'])
                && is_string($reasonCode) && PrivacyRequestInput::code($reasonCode)) {
                $deadlineCode = (string) $route->getArgument('deadlineCode');
                if (!PrivacyRequestInput::code($deadlineCode)) {
                    return $responses->notFound();
                }
                $due = $service->extendDeadline(AuthenticatedIdentity::fromRequest($request), $id,
                    $deadlineCode, $reasonCode);
                return $responses->success(['id' => $id, 'deadlineCode' => $deadlineCode, 'extendedDueAt' => $due->format(DATE_ATOM)]);
            }
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
    }
}
