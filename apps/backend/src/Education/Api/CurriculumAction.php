<?php

declare(strict_types=1);

namespace App\Education\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\CurriculumService;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class CurriculumAction
{
    public function __invoke(ServerRequestInterface $request, CurrentRoute $route, EducationAccess $access,
        CurriculumService $curriculum, ResponseFactory $responses): ResponseInterface
    {
        try {
            $operation = (string) $route->getArgument('operation');
            $scope = $operation === 'own_curriculum_progress'
                ? $access->studentScope(AuthenticatedIdentity::fromRequest($request),
                    $request->getHeaderLine('X-Education-Organization'), 'education.student.use')
                : $access->scope(AuthenticatedIdentity::fromRequest($request),
                $request->getHeaderLine('X-Education-Organization'),
                $operation === 'create_student' ? 'education.students.create' : 'education.people.manage');
            $id = $route->getArgument('id');
            $id = is_string($id) ? $id : null;
            if ($request->getMethod() === 'GET') return $responses->success($curriculum->read($operation, $scope, $id, $request->getQueryParams()));
            $body = $request->getParsedBody() ?? [];
            if (!is_array($body)) throw new EducationError(422, 'invalid_input');
            return $responses->success($curriculum->write($operation, $scope, $body, $id))
                ->withStatus(str_starts_with($operation, 'create_') || $operation === 'assign_program' ? 201 : 200);
        } catch (EducationError $error) {
            return $responses->error(match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED, 404 => ApiMessage::HTTP_NOT_FOUND,
                default => ApiMessage::VALIDATION_FAILED,
            }, httpCode: $error->status, meta: ['educationCode' => $error->reason]);
        }
    }
}
