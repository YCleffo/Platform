<?php

declare(strict_types=1);

namespace App\Core\Privacy\Api;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\LegalRepresentationService;
use DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ManageRepresentationAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        LegalRepresentationService $representations,
        ResponseFactory $responses,
    ): ResponseInterface {
        $operation = (string) $route->getArgument('operation');
        $input = $request->getParsedBody() ?? [];
        if (!is_array($input) || count($input) > 9) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        $actor = AuthenticatedIdentity::fromRequest($request);
        try {
            if ($operation === 'declare') {
                if (!$this->validDeclaration($input)) {
                    return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
                }
                /** @var list<string> $scopes */
                $scopes = $input['scopes'];
                $id = $representations->declare(
                    $actor,
                    (string) $input['subjectPersonId'],
                    (string) $input['representativePersonId'],
                    (string) $input['authorityType'],
                    (string) $input['validFrom'],
                    isset($input['validUntil']) ? (string) $input['validUntil'] : null,
                    isset($input['evidenceType']) ? (string) $input['evidenceType'] : null,
                    isset($input['evidenceReference']) ? (string) $input['evidenceReference'] : null,
                    isset($input['evidenceSha256']) ? (string) $input['evidenceSha256'] : null,
                    $scopes,
                );
                return $responses->success(['id' => $id, 'status' => 'declared'])->withStatus(Status::CREATED);
            }
            $id = (string) $route->getArgument('id');
            if (!self::uuid($id)) {
                return $responses->notFound();
            }
            if ($operation === 'verify' && $input === []) {
                $changed = $representations->verify($actor, $id);
                return $responses->success(['id' => $id, 'status' => 'verified', 'changed' => $changed]);
            }
            if ($operation === 'revoke' && array_keys($input) === ['reasonCode']
                && is_string($input['reasonCode']) && self::code($input['reasonCode'])) {
                $changed = $representations->revoke($actor, $id, $input['reasonCode']);
                return $responses->success(['id' => $id, 'status' => 'revoked', 'changed' => $changed]);
            }
        } catch (DomainException|InvalidArgumentException) {
            return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
        }
        return $responses->error(ApiMessage::VALIDATION_INVALID, httpCode: Status::UNPROCESSABLE_ENTITY);
    }

    /** @param array<array-key, mixed> $input */
    private function validDeclaration(array $input): bool
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, ['subjectPersonId', 'representativePersonId', 'authorityType', 'validFrom', 'validUntil', 'evidenceType', 'evidenceReference', 'evidenceSha256', 'scopes'], true)) {
                return false;
            }
        }
        $subject = $input['subjectPersonId'] ?? null;
        $representative = $input['representativePersonId'] ?? null;
        $type = $input['authorityType'] ?? null;
        $from = $input['validFrom'] ?? null;
        $scopes = $input['scopes'] ?? null;
        if (!is_string($subject) || !self::uuid($subject)
            || !is_string($representative) || !self::uuid($representative)
            || !is_string($type) || !self::code($type)
            || !is_string($from) || !self::timestamp($from)
            || !is_array($scopes) || !array_is_list($scopes) || $scopes === [] || count($scopes) > 32) {
            return false;
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !self::code($scope)) {
                return false;
            }
        }
        if (isset($input['validUntil']) && (!is_string($input['validUntil']) || !self::timestamp($input['validUntil']))) {
            return false;
        }
        if (isset($input['evidenceType']) && (!is_string($input['evidenceType']) || !self::code($input['evidenceType']))) {
            return false;
        }
        if (isset($input['evidenceReference']) && (!is_string($input['evidenceReference'])
            || strlen($input['evidenceReference']) > 512 || trim($input['evidenceReference']) === ''
            || preg_match('/[\x00-\x1F]/', $input['evidenceReference']))) {
            return false;
        }
        return !isset($input['evidenceSha256'])
            || (is_string($input['evidenceSha256']) && preg_match('/^[a-f0-9]{64}$/D', $input['evidenceSha256']) === 1);
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $value) === 1;
    }

    private static function code(string $value): bool
    {
        return strlen($value) <= 120 && preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $value) === 1;
    }

    private static function timestamp(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1
            && strtotime($value) !== false;
    }
}
