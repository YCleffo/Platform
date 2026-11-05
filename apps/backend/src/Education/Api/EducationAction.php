<?php

declare(strict_types=1);

namespace App\Education\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\EducationService;
use App\Education\Application\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class EducationAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route, EducationAccess $access,
        EducationService $education, ResponseFactory $responses, StreamFactoryInterface $streams): ResponseInterface
    {
        try {
            $identity = AuthenticatedIdentity::fromRequest($request);
            $operation = (string) $route->getArgument('operation');
            if ($operation === 'context') return $responses->success($access->context($identity));
            $permission = match ($operation) {
                'groups', 'group', 'students', 'candidates', 'create_group', 'enroll_student' => 'education.people.manage',
                'create_lesson', 'create_event', 'mark_attendance' => 'education.schedule.manage',
                'upload_file', 'file_download', 'files', 'materials' => 'education.files.manage',
                'threads', 'thread', 'messages', 'colleagues', 'start_thread', 'send_message', 'read_thread',
                    'read_notification', 'notifications' => 'education.messages.use',
                default => 'education.access',
            };
            $scope = $access->scope($identity, $request->getHeaderLine('X-Education-Organization'), $permission);
            $argument = $route->getArgument('id'); $id = is_string($argument) ? $argument : null;
            if ($operation === 'file_download') {
                $file = $education->download($scope, Input::uuid($id));
                return $responses->success()->withBody($streams->createStreamFromFile($file['path']))
                    ->withHeader('Content-Type', $file['mime'])->withHeader('Content-Length', (string) $file['size'])
                    ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                    ->withHeader('Content-Disposition', "attachment; filename=\"download\"; filename*=UTF-8''" . rawurlencode($file['name']));
            }
            if ($request->getMethod() === 'GET') {
                return $responses->success($education->read($operation, $scope, $request->getQueryParams(), $id));
            }
            $body = $request->getParsedBody();
            if ($body !== null && !is_array($body)) throw new EducationError(422, 'invalid_input');
            /** @var array<string,mixed> $body */
            $body = $body ?? [];
            if ($operation === 'upload_file') {
                if (array_diff(array_keys($body), ['lessonId', 'purpose']) !== []) throw new EducationError(422, 'invalid_input');
                $purpose = $body['purpose'] ?? 'material';
                if (!in_array($purpose, ['material', 'avatar'], true)) throw new EducationError(422, 'invalid_file');
                $upload = $request->getUploadedFiles()['file'] ?? null;
                if (!$upload instanceof UploadedFileInterface) throw new EducationError(422, 'invalid_file');
                $lessonId = ($body['lessonId'] ?? '') === '' ? null : Input::uuid($body['lessonId']);
                return $responses->success($education->upload($scope, $upload, $lessonId, $purpose === 'avatar'))->withStatus(201);
            }
            $studentId = $route->getArgument('studentId');
            $result = $education->write($operation, $scope, $body, $id, is_string($studentId) ? $studentId : null);
            return $responses->success($result)->withStatus(in_array($operation,
                ['create_group', 'enroll_student', 'create_lesson', 'create_event', 'start_thread', 'send_message'], true) ? 201 : 200);
        } catch (EducationError $error) {
            $code = match ($error->status) { 403 => ApiMessage::AUTH_PERMISSION_DENIED, 404 => ApiMessage::HTTP_NOT_FOUND,
                503 => ApiMessage::HTTP_SERVICE_UNAVAILABLE, default => ApiMessage::VALIDATION_FAILED };
            return $responses->error($code, httpCode: $error->status, meta: ['educationCode' => $error->reason]);
        }
    }
}
