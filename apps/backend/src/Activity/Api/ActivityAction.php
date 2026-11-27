<?php

declare(strict_types=1);

namespace App\Activity\Api;

use App\Activity\Application\ActivityEngineService;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ActivityAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ActivityEngineService $engine,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $identity = AuthenticatedIdentity::fromRequest($request);
            $operation = (string) $route->getArgument('operation');
            $idArg = $route->getArgument('id');
            $id = is_string($idArg) ? $idArg : '';

            $body = $request->getParsedBody();
            /** @var array<string, mixed> $body */
            $body = is_array($body) ? $body : [];
            /** @var array<string, mixed> $query */
            $query = $request->getQueryParams();

            $orgId = $request->getHeaderLine('X-Education-Organization');
            if ($orgId === '') {
                $orgId = (string) ($request->getHeaderLine('X-Platform-Organization') ?: ($body['organizationId'] ?? $query['organizationId'] ?? ''));
            }
            if ($orgId === '') {
                throw new HttpError(400, 'missing_organization_header');
            }

            $result = match ($operation) {
                'plugins' => $engine->listPlugins($identity, $orgId),
                'activities' => $engine->listActivities($identity, $orgId, $query),
                'activity' => $engine->getActivity($identity, $orgId, $id),
                'create_activity' => $engine->createActivity($identity, $orgId, $body),
                'update_activity' => $engine->updateActivity($identity, $orgId, $id, $body),
                'activity_versions' => $engine->listVersions($identity, $orgId, $id),
                'duplicate_activity' => $engine->duplicateActivity($identity, $orgId, $id),
                'assign_activity' => $engine->assignActivity($identity, $orgId, $id, $body),
                'list_assignments' => $engine->listAssignments($identity, $orgId, $query),
                'start_attempt' => $engine->startAttempt($identity, $orgId, $id, $body),
                'get_attempt' => $engine->getAttempt($identity, $orgId, $id),
                'save_draft' => $engine->saveDraft($identity, $orgId, $id, $body),
                'submit_attempt' => $engine->submitAttempt($identity, $orgId, $id, $body),
                'review_attempt' => $engine->reviewAttempt($identity, $orgId, $id, $body),
                'regrade_attempt' => $engine->regradeAttempt($identity, $orgId, $id, $body),
                'publish_attempt_results' => $engine->publishAttemptResults($identity, $orgId, $id, (bool) ($body['publish'] ?? true)),
                'list_activity_attempts' => $engine->listActivityAttempts($identity, $orgId, $id, $query),
                'question_bank_list' => $engine->listQuestionBank($identity, $orgId, $query),
                'question_bank_create' => $engine->createQuestionBankItem($identity, $orgId, $body),
                'question_bank_archive' => $engine->archiveQuestionBankItem($identity, $orgId, $id, (bool) ($body['restore'] ?? false)),
                'question_bank_import' => $engine->importQuestionBank($identity, $orgId, $body),
                'analytics' => $engine->analytics($identity, $orgId, $query),
                'bootstrap_typing' => $engine->bootstrapTypingActivity($identity, $orgId),
                'bootstrap_plugin' => $engine->bootstrapPluginActivity($identity, $orgId, $id),
                default => throw new HttpError(404, 'not_found'),
            };

            $response = $responses->success($result);
            if ($request->getMethod() === 'POST' && in_array($operation, ['create_activity', 'duplicate_activity', 'assign_activity', 'start_attempt', 'question_bank_create'], true)) {
                return $response->withStatus(Status::CREATED);
            }
            return $response;
        } catch (HttpError $error) {
            return $responses->error($error->reason, $error->status);
        }
    }
}
