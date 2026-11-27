<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\Api\Shared\ResponseFactory;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Auth\Application\AuthenticationService;
use App\Core\Client\Domain\ClientContext;
use App\Media\Application\MediaAccessAuthorizer;
use App\Media\Application\MediaError;
use App\Media\Application\MediaScope;
use App\Media\Application\MediaService;
use App\Media\Domain\MediaKind;
use App\Media\Domain\MediaStatus;
use App\Storage\Application\StorageFileAdapter;
use HttpSoft\Message\Response;
use HttpSoft\Message\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class MediaAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        MediaService $service,
        ResponseFactory $responses,
        ?StorageFileAdapter $storage = null,
        ?ConnectionInterface $db = null,
        ?MediaAccessAuthorizer $authorizer = null,
        ?AuthenticationService $authentication = null,
    ): ResponseInterface {
        try {
            $operation = (string) $route->getArgument('operation');
            $id = $route->getArgument('id');
            $id = is_string($id) ? $id : null;

            $identity = $request->getAttribute(AuthenticatedIdentity::ATTRIBUTE);
            $personId = ($identity instanceof AuthenticatedIdentity) ? $identity->personId : null;
            $accountId = ($identity instanceof AuthenticatedIdentity) ? $identity->accountId : null;
            $organizationId = $request->getHeaderLine('X-Education-Organization');
            if ($organizationId === '') {
                $query = $request->getQueryParams();
                $organizationId = isset($query['org']) && is_string($query['org']) ? $query['org'] : '';
            }

            // Ticket-based playback auth or Bearer fallback for stream
            if ($operation === 'stream' && $id !== null) {
                $ticket = $request->getQueryParams()['ticket'] ?? null;
                if (is_string($ticket) && $ticket !== '') {
                    $verified = MediaService::verifyTicket($ticket);
                    if ($verified !== null && $verified['assetId'] === $id) {
                        $personId = $verified['personId'];
                        $organizationId = $verified['org'];
                    }
                } elseif ($personId === null && $authentication !== null) {
                    $authHeader = $request->getHeaderLine('Authorization');
                    if (preg_match('/\ABearer ([A-Za-z0-9_-]+)\z/', $authHeader, $matches) === 1) {
                        $bearerIdentity = $authentication->authenticate($matches[1], ClientContext::fromRequest($request));
                        if ($bearerIdentity instanceof AuthenticatedIdentity) {
                            $personId = $bearerIdentity->personId;
                            $accountId = $bearerIdentity->accountId;
                        }
                    }
                }
            }

            if ($personId === null) {
                throw new MediaError(401, 'auth_required');
            }
            if ($organizationId === '') {
                throw new MediaError(400, 'missing_organization_header');
            }

            // Tenant isolation & membership verification
            if ($db !== null) {
                $isMember = $db->createCommand(
                    'SELECT 1 FROM core.organization_memberships
                     WHERE person_id = CAST(:person AS uuid) AND organization_id = CAST(:org AS uuid) AND status = \'active\'',
                    [':person' => $personId, ':org' => $organizationId],
                )->queryScalar();
                if ($isMember === false) {
                    throw new MediaError(403, 'access_denied');
                }
            }

            $scope = new MediaScope($organizationId, $accountId ?? $personId, $personId);

            // Binary upload operation: reads body, writes to S3, verifies integrity & sets READY
            if ($operation === 'upload') {
                if ($id === null) {
                    throw new MediaError(404, 'not_found');
                }
                if ($db === null) {
                    throw new MediaError(503, 'database_unavailable');
                }
                if ($storage === null) {
                    throw new MediaError(503, 'storage_unavailable');
                }

                $asset = $db->createCommand(
                    'SELECT a.id::text, a.kind, a.size_bytes, a.sha256, a.original_mime, a.status,
                            a.storage_key::text AS storage_key, s.staging_key, s.expires_at
                     FROM media.assets a
                     LEFT JOIN media.upload_sessions s ON s.asset_id = a.id
                     WHERE a.id = CAST(:id AS uuid)
                       AND a.organization_id = CAST(:org AS uuid)
                       AND a.owner_person_id = CAST(:person AS uuid)',
                    [':id' => $id, ':org' => $organizationId, ':person' => $personId],
                )->queryOne();

                if (!is_array($asset)) {
                    throw new MediaError(404, 'not_found');
                }
                if ($asset['status'] === MediaStatus::Ready->value) {
                    throw new MediaError(409, 'already_ready');
                }
                if ($asset['status'] === MediaStatus::Cancelled->value) {
                    throw new MediaError(409, 'upload_cancelled');
                }
                if ($asset['status'] === MediaStatus::Expired->value) {
                    throw new MediaError(409, 'session_expired');
                }

                if ($asset['expires_at'] === null) {
                    throw new MediaError(409, 'session_expired');
                }
                $expiresTs = strtotime((string) $asset['expires_at']);
                if ($expiresTs !== false && time() > $expiresTs) {
                    throw new MediaError(409, 'session_expired');
                }

                $bodyStream = $request->getBody();
                $temp = fopen('php://temp', 'w+b');
                if ($temp === false) {
                    throw new MediaError(503, 'temporary_storage_unavailable');
                }

                $bodyStream->rewind();
                $totalBytes = 0;
                $hashContext = hash_init('sha256');
                while (!$bodyStream->eof()) {
                    $chunk = $bodyStream->read(65536);
                    $chunkLen = strlen($chunk);
                    if ($chunkLen === 0) {
                        continue;
                    }
                    $totalBytes += $chunkLen;
                    if ($totalBytes > ((int) $asset['size_bytes'] + 1048576)) {
                        fclose($temp);
                        throw new MediaError(413, 'size_exceeded');
                    }
                    hash_update($hashContext, $chunk);
                    if (fwrite($temp, $chunk) !== $chunkLen) {
                        fclose($temp);
                        throw new MediaError(503, 'temporary_storage_unavailable');
                    }
                }
                $actualSha256 = hash_final($hashContext);
                rewind($temp);

                if ($totalBytes !== (int) $asset['size_bytes']) {
                    fclose($temp);
                    throw new MediaError(422, 'size_mismatch');
                }

                if (strtolower($actualSha256) !== strtolower((string) $asset['sha256'])) {
                    fclose($temp);
                    throw new MediaError(422, 'checksum_mismatch');
                }

                $sample = fread($temp, 2048);
                rewind($temp);
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $sample !== false ? ($finfo->buffer($sample) ?: (string) $asset['original_mime']) : (string) $asset['original_mime'];

                $mediaKind = MediaKind::tryFrom((string) $asset['kind']);
                if ($mediaKind !== null && !MediaService::isMimeAllowedForKind($mediaKind, $detectedMime, (string) $asset['original_mime'])) {
                    fclose($temp);
                    throw new MediaError(422, 'unsupported_mime_type');
                }
                $verifiedMime = $detectedMime === 'application/octet-stream'
                    ? MediaService::baseMimeType((string) $asset['original_mime'])
                    : MediaService::baseMimeType($detectedMime);

                $storage->write((string) $asset['storage_key'], $temp);
                if (!$storage->exists((string) $asset['storage_key'])) {
                    fclose($temp);
                    throw new MediaError(503, 'storage_write_failed');
                }
                fclose($temp);

                $affected = $db->createCommand(
                    'UPDATE media.assets
                     SET status = :ready, verified_mime = :mime, updated_at = now()
                     WHERE id = CAST(:id AS uuid)
                       AND status IN (:reserved, :uploading, :uploaded, :processing)',
                    [
                        ':ready' => MediaStatus::Ready->value,
                        ':mime' => $verifiedMime,
                        ':id' => $id,
                        ':reserved' => MediaStatus::Reserved->value,
                        ':uploading' => MediaStatus::Uploading->value,
                        ':uploaded' => MediaStatus::Uploaded->value,
                        ':processing' => MediaStatus::Processing->value,
                    ],
                )->execute();

                if ($affected === 0) {
                    throw new MediaError(409, 'already_ready');
                }

                $db->createCommand(
                    'DELETE FROM media.upload_sessions WHERE asset_id = CAST(:id AS uuid)',
                    [':id' => $id],
                )->execute();

                return $responses->success([
                    'id' => $id,
                    'status' => 'READY',
                    'sizeBytes' => $totalBytes,
                    'sha256' => $actualSha256,
                    'verifiedMime' => $verifiedMime,
                ]);
            }

            // Range streaming operation: authorized playback with 206 Partial Content
            if ($operation === 'stream') {
                if ($id === null) {
                    throw new MediaError(404, 'not_found');
                }
                if ($db === null) {
                    throw new MediaError(503, 'database_unavailable');
                }

                $asset = $service->assertReadAccess($organizationId, $personId, $id, $authorizer);
                if ($asset['status'] !== 'READY') {
                    throw new MediaError(404, 'media_not_ready');
                }

                if ($storage === null) {
                    throw new MediaError(503, 'storage_unavailable');
                }

                $storageKey = (string) $asset['storageKey'];
                $mime = (string) ($asset['verifiedMime'] ?? $asset['originalMime']);
                $fileSize = (int) $asset['sizeBytes'];

                $rangeHeader = $request->getHeaderLine('Range');
                if ($rangeHeader !== '' && preg_match('/bytes=(?:(\d+)-(\d+)?|-(\d+))/', $rangeHeader, $matches)) {
                    if (isset($matches[3]) && $matches[3] !== '') {
                        // Suffix range: bytes=-N
                        $suffix = (int) $matches[3];
                        $start = max(0, $fileSize - $suffix);
                        $end = $fileSize - 1;
                    } else {
                        $start = (int) $matches[1];
                        $end = isset($matches[2]) && $matches[2] !== '' ? min((int) $matches[2], $fileSize - 1) : ($fileSize - 1);
                    }

                    if ($start >= $fileSize || $start > $end || $fileSize === 0) {
                        $resp = (new Response(Status::RANGE_UNSATISFIABLE))
                            ->withHeader('Content-Range', "bytes */$fileSize")
                            ->withHeader('Accept-Ranges', 'bytes')
                            ->withHeader('Cache-Control', 'no-store, no-transform');
                        return $responses->withLocaleHeaders($resp);
                    }

                    $length = $end - $start + 1;
                    if (method_exists($storage, 'openRange')) {
                        /** @var resource $rangeStream */
                        $rangeStream = $storage->openRange($storageKey, $start, $end);
                    } else {
                        $fullStream = $storage->open($storageKey);
                        $meta = stream_get_meta_data($fullStream);
                        if (!empty($meta['seekable'])) {
                            fseek($fullStream, $start);
                        } else {
                            $remainingSkip = $start;
                            while ($remainingSkip > 0 && !feof($fullStream)) {
                                $discard = fread($fullStream, min(65536, $remainingSkip));
                                if ($discard === false || $discard === '') {
                                    break;
                                }
                                $remainingSkip -= strlen($discard);
                            }
                        }
                        $rangeStream = fopen('php://temp', 'w+b');
                        if ($rangeStream === false) {
                            fclose($fullStream);
                            throw new MediaError(503, 'temporary_storage_unavailable');
                        }
                        if ($length > 0) {
                            stream_copy_to_stream($fullStream, $rangeStream, $length);
                            rewind($rangeStream);
                        }
                        fclose($fullStream);
                    }

                    $response = (new Response(Status::PARTIAL_CONTENT))
                        ->withHeader('Content-Type', $mime)
                        ->withHeader('Content-Range', "bytes $start-$end/$fileSize")
                        ->withHeader('Content-Length', (string) $length)
                        ->withHeader('Accept-Ranges', 'bytes')
                        ->withHeader('X-Content-Type-Options', 'nosniff')
                        ->withHeader('Cache-Control', 'no-store, no-transform')
                        ->withBody(new Stream($rangeStream));
                    return $responses->withLocaleHeaders($response);
                }

                $stream = $storage->open($storageKey);
                $response = (new Response(Status::OK))
                    ->withHeader('Content-Type', $mime)
                    ->withHeader('Content-Length', (string) $fileSize)
                    ->withHeader('Accept-Ranges', 'bytes')
                    ->withHeader('X-Content-Type-Options', 'nosniff')
                    ->withHeader('Cache-Control', 'no-store, no-transform')
                    ->withBody(new Stream($stream));
                if (($request->getQueryParams()['download'] ?? '') === '1') {
                    $fileName = (string) ($asset['originalName'] ?? 'download');
                    $response = $response->withHeader(
                        'Content-Disposition',
                        "attachment; filename*=UTF-8''" . rawurlencode($fileName),
                    );
                }
                return $responses->withLocaleHeaders($response);
            }

            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $query = $request->getQueryParams();

            /** @var array<string, mixed>|null $meta */
            $meta = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : null;

            $result = match ($operation) {
                'reserve' => $service->reserveUpload(
                    $scope,
                    (string) ($body['kind'] ?? ''),
                    (string) ($body['name'] ?? $body['originalName'] ?? ''),
                    (string) ($body['mime'] ?? ''),
                    (int) ($body['sizeBytes'] ?? 0),
                    (string) ($body['sha256'] ?? ''),
                ),
                'confirm' => $service->confirmUpload(
                    $scope,
                    $id ?? throw new MediaError(404, 'not_found'),
                    $meta,
                ),
                'cancel' => $service->cancelUpload(
                    $scope,
                    $id ?? throw new MediaError(404, 'not_found'),
                ),
                'rename' => $service->renameAsset(
                    $scope,
                    $id ?? throw new MediaError(404, 'not_found'),
                    (string) ($body['name'] ?? $body['originalName'] ?? ''),
                ),
                'move' => $service->moveAsset(
                    $scope,
                    $id ?? throw new MediaError(404, 'not_found'),
                    (string) ($body['folderPath'] ?? ''),
                ),
                'copy' => $service->copyAsset(
                    $scope,
                    $id ?? throw new MediaError(404, 'not_found'),
                    isset($body['folderPath']) && is_string($body['folderPath']) ? $body['folderPath'] : null,
                ),
                'get' => (static function () use ($service, $scope, $id, $organizationId, $personId, $authorizer): array {
                    $targetId = $id ?? throw new MediaError(404, 'not_found');
                    $service->assertReadAccess($organizationId, $personId, $targetId, $authorizer);
                    return $service->asset($scope, $targetId) ?? throw new MediaError(404, 'not_found');
                })(),
                'playback' => (static function () use ($service, $scope, $id, $query, $organizationId, $personId, $authorizer): array {
                    $targetId = $id ?? throw new MediaError(404, 'not_found');
                    $service->assertReadAccess($organizationId, $personId, $targetId, $authorizer);
                    return $service->playbackUrl(
                        $scope,
                        $targetId,
                        isset($query['variant']) && is_string($query['variant']) ? $query['variant'] : null,
                    );
                })(),
                'list' => $service->listAssets(
                    $scope,
                    isset($query['kind']) && is_string($query['kind']) ? $query['kind'] : null,
                    isset($query['offset']) && is_numeric($query['offset']) ? (int) $query['offset'] : 0,
                ),
                default => throw new MediaError(404, 'not_found'),
            };

            return $responses->success($result);
        } catch (MediaError $error) {
            return $responses->error($error->reason, $error->status);
        }
    }
}
