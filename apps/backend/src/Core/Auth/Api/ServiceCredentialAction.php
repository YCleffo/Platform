<?php

declare(strict_types=1);

namespace App\Core\Auth\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Api\Shared\Uuid;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\ServiceCredentialService;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\InputValidationException;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Validator\Result;

final readonly class ServiceCredentialAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ServiceCredentialService $credentials,
        ResponseFactory $responses,
    ): ResponseInterface {
        $accountId = (string) $route->getArgument('accountId');
        $credentialId = (string) ($route->getArgument('credentialId') ?? '');
        $operation = (string) $route->getArgument('operation');
        if (!Uuid::isValid($accountId) || ($credentialId !== '' && !Uuid::isValid($credentialId))) {
            return $responses->error(ApiMessage::VALIDATION_FAILED);
        }
        if ($operation === 'list') {
            return $responses->success($credentials->listFor($accountId));
        }
        $actor = AuthenticatedIdentity::fromRequest($request)->accountId;
        if ($operation === 'create') {
            [$scopes, $expiresAt] = self::input($request->getParsedBody());
            try {
                $issued = $credentials->create($accountId, $scopes, $expiresAt, $actor);
            } catch (\InvalidArgumentException) {
                return $responses->error(ApiMessage::VALIDATION_FAILED, httpCode: Status::UNPROCESSABLE_ENTITY);
            }
            return $issued === null
                ? $responses->error(ApiMessage::ACCOUNT_CONFLICT, httpCode: Status::CONFLICT)
                : $responses->success([...$issued['credential'], 'secret' => $issued['secret']])->withStatus(Status::CREATED);
        }
        if ($operation === 'rotate') {
            $issued = $credentials->rotate($accountId, $credentialId, $actor);
            return $issued === null ? $responses->notFound()
                : $responses->success([...$issued['credential'], 'secret' => $issued['secret']]);
        }
        if ($operation === 'revoke') {
            $record = $credentials->revoke($accountId, $credentialId, $actor);
            return $record === null ? $responses->notFound() : $responses->success($record);
        }
        return $responses->notFound();
    }

    /** @return array{list<string>, ?string} */
    private static function input(mixed $body): array
    {
        $result = new Result();
        if (!is_array($body) || array_is_list($body)) {
            throw new InputValidationException($result->addError(ApiMessage::VALIDATION_BODY_OBJECT_REQUIRED));
        }
        foreach (array_keys($body) as $key) {
            if (!in_array($key, ['scopes', 'expiresAt'], true)) {
                $result->addError(ApiMessage::VALIDATION_UNKNOWN_FIELD, valuePath: [(string) $key]);
            }
        }
        $scopes = $body['scopes'] ?? null;
        if (!is_array($scopes) || !array_is_list($scopes) || $scopes === [] || count($scopes) > 32) {
            $result->addError(ApiMessage::VALIDATION_FAILED, valuePath: ['scopes']);
        }
        $expiresAt = $body['expiresAt'] ?? null;
        if ($expiresAt !== null) {
            if (!is_string($expiresAt) || strlen($expiresAt) > 50
                || preg_match('/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $expiresAt) !== 1) {
                $result->addError(ApiMessage::VALIDATION_FAILED, valuePath: ['expiresAt']);
            } else {
                try {
                    if (new DateTimeImmutable($expiresAt) <= new DateTimeImmutable()) {
                        $result->addError(ApiMessage::VALIDATION_FAILED, valuePath: ['expiresAt']);
                    }
                } catch (\Exception) {
                    $result->addError(ApiMessage::VALIDATION_FAILED, valuePath: ['expiresAt']);
                }
            }
        }
        if (!$result->isValid()) {
            throw new InputValidationException($result);
        }
        return [$scopes, $expiresAt];
    }
}
