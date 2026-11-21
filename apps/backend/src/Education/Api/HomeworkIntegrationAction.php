<?php

declare(strict_types=1);

namespace App\Education\Api;

use App\Api\Shared\{ApiMessage, ResponseFactory};
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\{EducationAccess, EducationError, HomeworkActivityService, HomeworkFileService, Input};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface, StreamFactoryInterface, UploadedFileInterface};
use Yiisoft\Router\CurrentRoute;

final readonly class HomeworkIntegrationAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        EducationAccess $access,
        HomeworkActivityService $activities,
        HomeworkFileService $files,
        ResponseFactory $responses,
        StreamFactoryInterface $streams,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $identity = AuthenticatedIdentity::fromRequest($request);
            $org = $request->getHeaderLine('X-Education-Organization');
            if ($operation === 'download') {
                try {
                    $scope = $access->studentScope($identity, $org, 'education.student.use');
                } catch (EducationError $error) {
                    if ($error->status !== 403) {
                        throw $error;
                    }
                    $scope = $access->scope($identity, $org, 'education.people.manage');
                }
            } else {
                $scope = $operation === 'material' ? $access->scope($identity, $org, 'education.people.manage')
                    : $access->studentScope($identity, $org, str_contains($operation, 'activity') ? 'education.activities.use' : 'education.student.use');
            }
            $id = Input::uuid($route->getArgument('id'));
            if ($operation === 'download') {
                $file = $files->download($scope, $id);
                $fileStream = is_resource($file['stream']) ? $file['stream'] : throw new EducationError(500, 'invalid_stream');
                $fileMime = is_string($file['mime']) ? $file['mime'] : 'application/octet-stream';
                $fileName = is_string($file['name']) ? $file['name'] : 'download';
                return $responses->success()->withBody($streams->createStreamFromResource($fileStream))
                    ->withHeader('Content-Type', $fileMime)->withHeader('Content-Length', (string) $file['size'])
                    ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                    ->withHeader('X-Content-Type-Options', 'nosniff')
                    ->withHeader('Content-Disposition', "attachment; filename=\"download\"; filename*=UTF-8''" . rawurlencode($fileName));
            }
            $body = $request->getParsedBody() ?? [];
            if (!is_array($body)) {
                throw new EducationError(422, 'invalid_input');
            }
            if (in_array($operation, ['material','submission'], true)) {
                if ($body !== []) {
                    throw new EducationError(422, 'invalid_input');
                }
                $upload = $request->getUploadedFiles()['file'] ?? null;
                if (!$upload instanceof UploadedFileInterface) {
                    throw new EducationError(422, 'invalid_file');
                }
                return $responses->success($files->upload($scope, $id, $upload))->withStatus(201);
            }
            if (array_diff(array_keys($body), $operation === 'attach_activity' ? ['attemptId'] : []) !== []) {
                throw new EducationError(422, 'invalid_input');
            }
            return $responses->success(match ($operation) {
                'start_activity' => $activities->start($scope, $id),
                'attach_activity' => $activities->attach($scope, $id, Input::uuid($body['attemptId'] ?? null)),
                'unlink' => $files->unlink($scope, $id, Input::uuid($route->getArgument('fileId'))),
                default => throw new EducationError(404, 'not_found'),
            });
        } catch (EducationError $error) {
            return $responses->error(match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED, 404 => ApiMessage::HTTP_NOT_FOUND,
                503 => ApiMessage::HTTP_SERVICE_UNAVAILABLE, default => ApiMessage::VALIDATION_FAILED,
            }, httpCode: $error->status, meta: ['educationCode' => $error->reason, 'homeworkCode' => $error->reason]);
        }
    }
}
