<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\CorrectionCommand;
use App\Core\Privacy\Application\PersonalDataCorrectionService;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ManagePrivacyCorrectionAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        PersonalDataCorrectionService $service,
        ResponseFactory $responses,
    ): ResponseInterface {
        $id = (string) $route->getArgument('id');
        if (!PrivacyRequestInput::uuid($id)) {
            return $responses->notFound();
        }
        $input = $request->getParsedBody();
        $operation = (string) $route->getArgument('operation');
        if (!is_array($input)) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        try {
            $actor = AuthenticatedIdentity::fromRequest($request);
            if ($operation === 'confirm' && $input === []) {
                return $responses->success(['confirmed' => $service->confirmEvidence($actor, $id)]);
            }
            if ($operation === 'correct' && PrivacyRequestInput::keys(
                $input,
                ['contributorCode', 'resourceId', 'changes'],
            )) {
                $code = $input['contributorCode'] ?? null;
                $resource = $input['resourceId'] ?? null;
                $changes = $input['changes'] ?? null;
                if (is_string($code) && PrivacyRequestInput::code($code)
                    && is_string($resource) && PrivacyRequestInput::uuid($resource)
                    && is_array($changes) && $changes !== [] && !array_is_list($changes)) {
                    foreach (array_keys($changes) as $field) {
                        if (!is_string($field)) {
                            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                        }
                    }
                    /** @var array<string, mixed> $changes */
                    $executionId = $service->correct(
                        $actor,
                        $id,
                        new CorrectionCommand($code, $resource, $changes),
                    );
                    return $responses->success(['executionId' => $executionId]);
                }
            }
        } catch (DomainException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
    }
}
