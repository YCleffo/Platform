<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\ServiceCredentialService;
use App\Core\Auth\Application\ServiceScopePolicy;
use DomainException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;

final readonly class ApplyEntitlementsAction
{
    public function __invoke(
        ServerRequestInterface $request,
        ServiceCredentialService $credentials,
        ServiceScopePolicy $scopes,
        PostgresInstallationRepository $installations,
        EntitlementApplier $applier,
        ResponseFactory $responses,
    ): ResponseInterface {
        $identity = $credentials->authenticate(
            $request->getHeaderLine('Authorization'),
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown'),
        );
        if ($identity === null) {
            return $responses->error(ApiMessage::AUTH_REQUIRED, httpCode: Status::UNAUTHORIZED);
        }
        try {
            $scopes->requireScope($identity, 'runtime.entitlements.apply');
        } catch (DomainException) {
            return $responses->error(ApiMessage::AUTH_PERMISSION_DENIED, httpCode: Status::FORBIDDEN);
        }
        if (strlen((string) $request->getBody()) > 1500000
            || !str_starts_with($request->getHeaderLine('Content-Type'), 'application/json')) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        try {
            $body = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($body) || count($body) !== 3
                || !array_key_exists('documentBase64', $body)
                || !array_key_exists('signatureBase64', $body)
                || !array_key_exists('keyId', $body)
                || !is_string($body['documentBase64']) || !is_string($body['signatureBase64'])
                || !is_string($body['keyId'])
                || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/D', $body['keyId']) !== 1) {
                throw new DomainException('Invalid entitlement envelope.');
            }
            $document = base64_decode($body['documentBase64'], true);
            $signature = base64_decode($body['signatureBase64'], true);
            if (!is_string($document) || !is_string($signature)) {
                throw new DomainException('Invalid entitlement bytes.');
            }
            $applier->apply($installations->localId(), $document, $signature, $body['keyId']);
            return $responses->success(['applied' => true]);
        } catch (DomainException|\JsonException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
    }
}
