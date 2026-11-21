<?php

declare(strict_types=1);

namespace App\Education\Api;

use App\Api\Shared\{ApiMessage, ResponseFactory};
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\{EducationAccess, EducationError, EducationFileTransfers, Input, Scope};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Yiisoft\Router\CurrentRoute;

final readonly class EducationTransferAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        EducationAccess $access,
        EducationFileTransfers $transfers,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $identity = AuthenticatedIdentity::fromRequest($request);
            $organization = Input::uuid($request->getHeaderLine('X-Education-Organization'));
            $id = $route->getArgument('id');
            if (in_array($operation, ['read', 'complete', 'abort'], true)) {
                $kind = $transfers->accessKind(Input::uuid($id), $identity->accountId, $organization);
                $kindKind = is_string($kind['kind']) ? $kind['kind'] : 'file';
                $scope = $this->scope($access, $identity, $organization, $kindKind, $kind['studentId'] !== null);
            } elseif ($operation === 'download') {
                try {
                    $scope = $access->scope($identity, $organization, 'education.files.manage');
                } catch (EducationError $error) {
                    if ($error->status !== 403 || $error->reason !== 'access_denied') {
                        throw $error;
                    }
                    $scope = $access->studentScope($identity, $organization, 'education.student.use');
                }
            } elseif ($operation === 'homework_download') {
                try {
                    $scope = $access->studentScope($identity, $organization, 'education.student.use');
                } catch (EducationError $error) {
                    if ($error->status !== 403) {
                        throw $error;
                    }
                    $scope = $access->scope($identity, $organization, 'education.people.manage');
                }
            } else {
                $scope = $this->scope(
                    $access,
                    $identity,
                    $organization,
                    str_contains($operation, 'homework') ? 'homework' : 'file',
                    $operation === 'student_homework_start',
                );
            }
            $input = $request->getParsedBody() ?? [];
            if (!is_array($input)) {
                throw new EducationError(422, 'invalid_input');
            }
            if (in_array($operation, ['read', 'complete', 'abort', 'download', 'homework_download'], true) && $input !== []) {
                throw new EducationError(422, 'invalid_input');
            }
            $result = match ($operation) {
                'start' => $transfers->start($scope, 'file', null, $input),
                'homework_start', 'student_homework_start' => $transfers->start($scope, 'homework', Input::uuid($id), $input),
                'read' => $transfers->read($scope, Input::uuid($id)),
                'complete' => $transfers->complete($scope, Input::uuid($id)),
                'abort' => $transfers->abort($scope, Input::uuid($id)),
                'download' => $transfers->download($scope, Input::uuid($id), false),
                'homework_download' => $transfers->download($scope, Input::uuid($id), true),
                default => throw new EducationError(404, 'not_found'),
            };
            return $responses->success($result)->withStatus(str_ends_with($operation, 'start') ? 201 : 200);
        } catch (EducationError $error) {
            return $responses->error(match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED, 404 => ApiMessage::HTTP_NOT_FOUND,
                503 => ApiMessage::HTTP_SERVICE_UNAVAILABLE, default => ApiMessage::VALIDATION_FAILED,
            }, httpCode: $error->status, meta: ['educationCode' => $error->reason]);
        }
    }

    private function scope(EducationAccess $access, AuthenticatedIdentity $identity, string $organization, string $kind, bool $student): Scope
    {
        return $student ? $access->studentScope($identity, $organization, 'education.student.use')
            : $access->scope($identity, $organization, $kind === 'homework' ? 'education.people.manage' : 'education.files.manage');
    }
}
