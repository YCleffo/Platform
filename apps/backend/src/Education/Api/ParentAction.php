<?php

declare(strict_types=1);

namespace App\Education\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Core\Shared\Application\ConflictException;
use App\Education\Application\EducationAccess;
use App\Education\Application\EducationError;
use App\Education\Application\Input;
use App\Education\Application\ParentFamily;
use App\Education\Application\ParentReadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class ParentAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        EducationAccess $access,
        ParentFamily $family,
        ParentReadService $parents,
        ResponseFactory $responses,
        StreamFactoryInterface $streams,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $rawBody = $request->getParsedBody() ?? [];
            if (!is_array($rawBody) || (array_is_list($rawBody) && $rawBody !== [])) {
                throw new EducationError(422, 'invalid_input');
            }
            /** @var array<string, mixed> $body */
            $body = $rawBody;
            if ($operation === 'register') {
                return $responses->success($family->register($body, ClientContext::fromRequest($request)));
            }
            $identity = AuthenticatedIdentity::fromRequest($request);
            if ($operation === 'context') {
                return $responses->success($parents->context($identity));
            }
            if ($operation === 'activate') {
                return $responses->success($family->activate($identity, $body));
            }
            $organization = $request->getHeaderLine('X-Education-Organization');
            if (str_starts_with($operation, 'staff_')) {
                $scope = $access->scope($identity, $organization, 'education.people.manage');
                $student = $route->getArgument('studentId');
                $result = match ($operation) {
                    'staff_candidates' => $family->candidates($scope, $request->getQueryParams()),
                    'staff_list' => $family->list($scope, Input::uuid($student)),
                    'staff_history' => $family->history($scope, Input::uuid($student), $request->getQueryParams()),
                    default => $family->write(
                        substr($operation, 6),
                        $scope,
                        Input::uuid($student),
                        is_string($route->getArgument('personId')) ? $route->getArgument('personId') : null,
                        $body,
                    ),
                };
                return $responses->success($result)->withStatus($operation === 'staff_add' ? 201 : 200);
            }
            $scope = $access->parentScope($identity, $organization, Input::uuid($route->getArgument('studentId')));
            if ($operation === 'homework_material_download') {
                $file = $parents->homeworkMaterialDownload($scope, Input::uuid($route->getArgument('id')));
                return $responses->success()->withBody($streams->createStreamFromResource($file['stream']))
                    ->withHeader('Content-Type', $file['mime'])
                    ->withHeader('Content-Length', (string) $file['size'])
                    ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                    ->withHeader('Content-Disposition', "attachment; filename=\"download\"; filename*=UTF-8''" . rawurlencode($file['name']));
            }
            if ($operation === 'read_notification') {
                if ($body !== []) {
                    throw new EducationError(422, 'invalid_input');
                }
                return $responses->success($parents->readNotification($scope, Input::uuid($route->getArgument('id'))));
            }
            if ($operation === 'parent_start_thread') {
                return $responses->success($parents->startThread($scope, $body))->withStatus(201);
            }
            if ($operation === 'parent_send_message') {
                return $responses->success($parents->sendMessage($scope, Input::uuid($route->getArgument('id')), $body));
            }
            if ($operation === 'parent_react_message') {
                return $responses->success($parents->toggleReaction($scope, Input::uuid($route->getArgument('id')), $body));
            }
            if ($operation === 'parent_read_thread') {
                return $responses->success($parents->readThread($scope, Input::uuid($route->getArgument('id')), $body));
            }
            if ($operation === 'parent_thread') {
                return $responses->success($parents->thread($scope, Input::uuid($route->getArgument('id'))));
            }
            if ($operation === 'parent_messages') {
                $offset = (int) ($request->getQueryParams()['offset'] ?? 0);
                return $responses->success($parents->messages($scope, Input::uuid($route->getArgument('id')), $offset));
            }
            return $responses->success($parents->read($operation, $scope, $request->getQueryParams()));
        } catch (EducationError $error) {
            return $responses->error(match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED,404 => ApiMessage::HTTP_NOT_FOUND,
                default => ApiMessage::VALIDATION_FAILED,
            }, httpCode: $error->status, meta: ['educationCode' => $error->reason,'parentCode' => $error->reason]);
        } catch (ConflictException) {
            return $responses->error(ApiMessage::VALIDATION_FAILED, httpCode: 409, meta: ['educationCode' => 'identity_conflict','parentCode' => 'identity_conflict']);
        }
    }
}
