<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Storage\Application\StorageError;
use Aws\Exception\AwsException;
use Throwable;

/** Map provider errors onto stable reasons without exposing SDK requests or configuration values. */
final class S3Failure
{
    public static function storage(Throwable $error, bool $bucketProbe = false): StorageError
    {
        do {
            if ($error instanceof StorageError) {
                return $error;
            }
            if ($error instanceof AwsException) {
                return self::provider($error, $bucketProbe);
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return new StorageError(503, 's3_unavailable');
    }

    private static function provider(AwsException $error, bool $bucketProbe): StorageError
    {
        $code = $error->getAwsErrorCode();
        $status = $error->getStatusCode();
        $reason = match (true) {
            in_array($code, ['SignatureDoesNotMatch', 'InvalidSignatureException'], true) => 's3_signature_invalid',
            in_array($code, ['InvalidAccessKeyId', 'InvalidToken', 'ExpiredToken', 'InvalidSecurity'], true),
            $status === 401 => 's3_credentials_rejected',
            $code === 'NoSuchBucket', $bucketProbe && $status === 404 => 's3_bucket_missing',
            $status === 403, $code === 'AccessDenied' => 's3_access_denied',
            $status === 404 && !$bucketProbe => 'object_missing',
            default => 's3_unavailable',
        };

        return new StorageError($reason === 'object_missing' ? 404 : 503, $reason);
    }
}
