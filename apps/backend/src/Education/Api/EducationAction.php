<?php

declare(strict_types=1);

namespace App\Education\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\EducationService;
use App\Education\Application\CurriculumStore;
use App\Education\Application\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class EducationAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        EducationAccess $access,
        EducationService $education,
        CurriculumStore $curriculum,
        ResponseFactory $responses,
        StreamFactoryInterface $streams,
    ): ResponseInterface {
        try {
            $identity = AuthenticatedIdentity::fromRequest($request);
            $operation = (string) $route->getArgument('operation');
            if ($operation === 'context') {
                return $responses->success($access->context($identity));
            }
            if ($operation === 'activate_invite') {
                $personId = $access->assertAvailable($identity);
                $rawBody = $request->getParsedBody() ?? [];
                if (!is_array($rawBody)) {
                    throw new EducationError(422, 'invalid_input');
                }
                /** @var array<string, mixed> $body */
                $body = $rawBody;
                return $responses->success($education->activateInvite($identity, $personId, $body));
            }
            $permission = match ($operation) {
                'groups', 'group', 'group_students', 'students', 'candidates', 'create_group', 'update_group',
                'archive_group', 'enroll_student', 'remove_group_student', 'transfer_student',
                'group_journal', 'student', 'student_attendance', 'student_results', 'student_progress',
                'invite_student', 'revoke_student_account' => 'education.people.manage',
                'create_lesson', 'update_lesson', 'complete_lesson', 'cancel_lesson', 'create_event',
                'mark_attendance', 'save_lesson_report', 'save_lesson_result' => 'education.schedule.manage',
                'upload_file', 'file_download', 'files', 'materials', 'delete_file' => 'education.files.manage',
                'threads', 'thread', 'messages', 'colleagues', 'contacts', 'start_thread', 'send_message', 'react_message', 'read_thread',
                'read_notification', 'notifications' => 'education.messages.use',
                'activities_assign', 'activity_assignments', 'group_activity_results', 'activity_catalog', 'group_activity_progress', 'revoke_activity_attempt' => 'education.activities.manage',
                default => 'education.access',
            };
            $studentOperations = ['student_home', 'student_schedule', 'student_lessons', 'student_lesson',
                'student_activity_catalog', 'student_activity_config', 'student_attempt_history',
                'student_attempt','student_save_attempt_state','student_achievements','student_activity_progress',
                'student_typing_history', 'student_start_attempt', 'student_finish_attempt', 'student_notifications', 'student_read_notification',
                'student_contacts', 'student_threads', 'student_thread', 'student_messages', 'student_start_thread', 'student_send_message', 'student_react_message', 'student_read_thread'];
            $scope = in_array($operation, $studentOperations, true)
                ? $access->studentScope(
                    $identity,
                    $request->getHeaderLine('X-Education-Organization'),
                    in_array($operation, ['student_activity_catalog', 'student_activity_config', 'student_start_attempt',
                        'student_finish_attempt', 'student_attempt_history', 'student_typing_history','student_attempt',
                        'student_save_attempt_state','student_achievements','student_activity_progress'], true)
                        ? 'education.activities.use' : 'education.student.use',
                )
                : ($operation === 'header'
                    ? $this->headerScope($access, $identity, $request->getHeaderLine('X-Education-Organization'))
                    : $access->scope($identity, $request->getHeaderLine('X-Education-Organization'), $permission));
            $argument = $route->getArgument('id');
            $id = is_string($argument) ? \App\Education\Application\ActivityCode::canonical($argument) : null;
            if ($operation === 'file_download') {
                $file = $education->download($scope, Input::uuid($id));
                return $responses->success()->withBody($streams->createStreamFromResource($file['stream']))
                    ->withHeader('Content-Type', $file['mime'])->withHeader('Content-Length', (string) $file['size'])
                    ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                    ->withHeader('Content-Disposition', "attachment; filename=\"download\"; filename*=UTF-8''" . rawurlencode($file['name']));
            }
            if ($request->getMethod() === 'GET') {
                return $responses->success($education->read($operation, $scope, array_map(static fn(mixed $value): mixed => is_string($value) ? \App\Education\Application\ActivityCode::canonical($value) : $value, $request->getQueryParams()), $id, $identity));
            }
            $body = $request->getParsedBody();
            if ($body !== null && !is_array($body)) {
                throw new EducationError(422, 'invalid_input');
            }
            /** @var array<string,mixed> $body */
            $body = $body ?? [];
            if ($operation === 'student_read_notification') {
                $operation = 'read_notification';
            }
            if ($operation === 'upload_file') {
                if (array_diff(array_keys($body), ['lessonId', 'purpose']) !== []) {
                    throw new EducationError(422, 'invalid_input');
                }
                $purpose = $body['purpose'] ?? 'material';
                if (!in_array($purpose, ['material', 'avatar'], true)) {
                    throw new EducationError(422, 'invalid_file');
                }
                $upload = $request->getUploadedFiles()['file'] ?? null;
                if (!$upload instanceof UploadedFileInterface) {
                    throw new EducationError(422, 'invalid_file');
                }
                $lessonId = ($body['lessonId'] ?? '') === '' ? null : Input::uuid($body['lessonId']);
                if ($lessonId !== null) {
                    $scope = $curriculum->delegatedScope($scope, 'lesson', $lessonId);
                }
                return $responses->success($education->upload($scope, $upload, $lessonId, $purpose === 'avatar'))->withStatus(201);
            }
            $studentId = $route->getArgument('studentId');
            $result = $education->write($operation, $scope, $body, $id, is_string($studentId) ? $studentId : null, $identity);
            return $responses->success($result)->withStatus(in_array(
                $operation,
                ['create_group', 'enroll_student', 'create_lesson', 'create_event', 'start_thread', 'send_message',
                    'student_start_thread', 'student_send_message',
                    'invite_student', 'activities_assign', 'student_start_attempt'],
                true,
            ) ? 201 : 200);
        } catch (EducationError $error) {
            $code = match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED, 404 => ApiMessage::HTTP_NOT_FOUND,
                503 => ApiMessage::HTTP_SERVICE_UNAVAILABLE, default => ApiMessage::VALIDATION_FAILED,
            };
            return $responses->error($code, httpCode: $error->status, meta: ['educationCode' => $error->reason]);
        }
    }

    /** The shared shell header exposes only the authenticated person's metadata. */
    private function headerScope(EducationAccess $access, AuthenticatedIdentity $identity, string $organization): \App\Education\Application\Scope
    {
        try {
            return $access->scope($identity, $organization, 'education.access');
        } catch (EducationError $error) {
            if ($error->status !== 403 || $error->reason !== 'access_denied') {
                throw $error;
            }
            return $access->studentScope($identity, $organization, 'education.student.use');
        }
    }
}
