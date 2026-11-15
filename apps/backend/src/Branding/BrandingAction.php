<?php

declare(strict_types=1);

namespace App\Branding;

use App\Api\Shared\ApiMessage;
use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Client\Domain\ClientContext;
use App\Storage\Application\StorageError;
use HttpSoft\Message\Response;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;

final readonly class BrandingAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        BrandingSettings $branding,
        BrandingFiles $files,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $id = (string) $route->getArgument('id');
            if ($operation === 'icon') {
                $icon = $files->open($id);
                return (new Response())->withHeader('Content-Type', $icon['mime'])
                    ->withHeader('Content-Length', (string) $icon['sizeBytes'])
                    ->withHeader('X-Content-Type-Options', 'nosniff')
                    ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
                    ->withHeader('Cache-Control', 'public, max-age=86400, immutable')
                    ->withHeader('ETag', '"' . $icon['sha256'] . '"')
                    ->withBody(new Stream($icon['stream']));
            }
            $identity = AuthenticatedIdentity::fromRequest($request);
            $context = ClientContext::fromRequest($request);
            $input = $request->getParsedBody() ?? [];
            if (!is_array($input)) {
                throw new StorageError(422, 'invalid_input');
            }
            if ($operation !== 'name' && $operation !== 'start' && $input !== []) {
                throw new StorageError(422, 'invalid_input');
            }
            $result = match ($operation) {
                'name' => $branding->rename($input, $identity->accountId, $context),
                'start' => $files->start($input, $identity->accountId, $context),
                'refresh' => $files->refresh($id, $identity->accountId),
                'complete' => $files->complete($id, $identity->accountId, $context),
                'abort' => $files->abort($id, $identity->accountId, $context),
                'remove' => $files->remove($identity->accountId, $context),
                default => throw new StorageError(404, 'not_found'),
            };
            return $responses->success($result)->withHeader('Cache-Control', 'no-store');
        } catch (StorageError $error) {
            $code = match ($error->status) {
                403 => ApiMessage::AUTH_PERMISSION_DENIED,
                404 => ApiMessage::HTTP_NOT_FOUND,
                503 => ApiMessage::HTTP_SERVICE_UNAVAILABLE,
                default => ApiMessage::VALIDATION_FAILED,
            };
            return $responses->error($code, httpCode: $error->status,
                meta: ['brandingCode' => $error->reason])->withHeader('Cache-Control', 'no-store');
        }
    }
}
