<?php

declare(strict_types=1);

namespace App\Education\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Education\Application\AssessmentService;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class AssessmentAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        EducationAccess $access,
        AssessmentService $assessments,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $identity = AuthenticatedIdentity::fromRequest($request);
            $organization = $request->getHeaderLine('X-Education-Organization');

            $isStudent = str_starts_with($operation, 'student_');
            $scope = $isStudent
                ? $access->studentScope($identity, $organization, 'education.activities.use')
                : $access->scope($identity, $organization, 'education.activities.manage');

            $argument = $route->getArgument('id');
            $id = is_string($argument) ? $argument : null;
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $queryParams = $request->getQueryParams();

            $result = match ($operation) {
                'assessments' => $request->getMethod() === 'POST'
                    ? $assessments->createAssessment($scope, $body)
                    : $assessments->listAssessments($scope, $queryParams),

                'assessment_detail' => $request->getMethod() === 'PUT'
                    ? $assessments->updateAssessment($scope, (string) $id, $body)
                    : $assessments->getAssessment($scope, (string) $id),

                'publish_assessment' => $assessments->publishAssessment($scope, (string) $id, $body),
                'assessment_versions' => $assessments->listVersions($scope, (string) $id),

                'question_bank' => $request->getMethod() === 'POST'
                    ? $assessments->createQuestionBankItem($scope, $body)
                    : $assessments->listQuestionBank($scope, $queryParams),

                'delete_question_bank_item' => (function () use ($assessments, $scope, $id): array {
                    $assessments->deleteQuestionBankItem($scope, (string) $id);
                    return ['deleted' => true];
                })(),

                'assessment_attempts_list' => $assessments->listAssessmentAttempts($scope, (string) $id),
                'assessment_attempt_review' => $assessments->reviewAttempt($scope, (string) $id, $body),

                'student_assessments' => $assessments->listStudentAssessments($scope),
                'student_start_assessment' => $assessments->startStudentAttempt($scope, (string) $id, $body),
                'student_assessment_attempt' => $assessments->getStudentAttempt($scope, (string) $id),
                'student_save_assessment_attempt' => $assessments->saveStudentAttempt($scope, (string) $id, $body),
                'student_submit_assessment_attempt' => $assessments->submitStudentAttempt($scope, (string) $id, $body),

                default => throw new EducationError(404, 'not_found'),
            };

            return $responses->success($result);
        } catch (EducationError $error) {
            return $responses->error(match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED,
                404 => ApiMessage::HTTP_NOT_FOUND,
                default => ApiMessage::VALIDATION_FAILED,
            }, httpCode: $error->status, meta: ['educationCode' => $error->reason]);
        }
    }
}
