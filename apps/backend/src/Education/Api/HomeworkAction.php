<?php

declare(strict_types=1);

namespace App\Education\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\HomeworkService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class HomeworkAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route, EducationAccess $access,
        HomeworkService $homework, ResponseFactory $responses): ResponseInterface
    {
        try {
            $operation = (string) $route->getArgument('operation');
            $student = str_starts_with($operation, 'student_') || in_array($operation, ['save_draft', 'submit'], true);
            $identity = AuthenticatedIdentity::fromRequest($request);
            $organization = $request->getHeaderLine('X-Education-Organization');
            $scope = $student ? $access->studentScope($identity, $organization, 'education.student.use')
                : $access->scope($identity, $organization, $operation === 'review' ? 'education.homework.review' : 'education.homework.manage');
            $id = $route->getArgument('id');
            $id = is_string($id) ? $id : null;
            if ($request->getMethod() === 'GET') return $responses->success($homework->read($operation, $scope, $request->getQueryParams(), $id));
            $body = $request->getParsedBody() ?? [];
            if (!is_array($body) || (array_is_list($body) && $body !== [])) throw new EducationError(422, 'invalid_input');
            return $responses->success($homework->write($operation, $scope, $body, $id))->withStatus($operation === 'create' ? 201 : 200);
        } catch (EducationError $error) {
            return $responses->error(match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED, 404 => ApiMessage::HTTP_NOT_FOUND,
                default => ApiMessage::VALIDATION_FAILED,
            }, httpCode: $error->status, meta: ['educationCode' => $error->reason, 'homeworkCode' => $error->reason]);
        }
    }
}
