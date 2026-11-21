<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Storage\Application\StorageError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/S3Fixture.php';

/** Independent assertions against the actual S3 server, not a local adapter or SDK mock. */
final class S3ObjectIntegrationTest extends TestCase
{
    use S3Fixture;

    protected function setUp(): void
    {
        $this->startS3Fixture();
    }
    protected function tearDown(): void
    {
        $this->finishS3Fixture();
    }

    public function testSdkStreamsMultipartSizedObjectAndVerifiesActualBytes(): void
    {
        $key = $this->ownKey('stream.bin');
        $size = 12 * 1024 * 1024 + 19;
        $source = $this->dataStream($size);
        $expected = hash_init('sha256');
        hash_update_stream($expected, $source);
        $sha = hash_final($expected);
        rewind($source);
        $this->objects->put($this->s3Config->bucketStorage, $key, $source);
        fclose($source);
        self::assertSame($size, $this->objects->head($this->s3Config->bucketStorage, $key)['sizeBytes']);
        self::assertSame(['sha256' => $sha, 'sizeBytes' => $size], $this->objects->verify($this->s3Config->bucketStorage, $key, $size));
    }

    public function testPublicHostSignatureSupportsPutRangeAndPrivateBuckets(): void
    {
        $key = $this->ownKey('signed.txt');
        $text = 'A private version for the school documents.';
        $put = $this->clients->public()->getCommand('PutObject', ['Bucket' => $this->s3Config->bucketStorage,'Key' => $key,
            'ContentLength' => strlen($text),'ChecksumSHA256' => base64_encode(hash('sha256', $text, true))]);
        $result = $this->signedHttp($put, 'PUT', $text);
        self::assertSame(200, $result['status']);
        $get = $this->clients->public()->getCommand('GetObject', ['Bucket' => $this->s3Config->bucketStorage,'Key' => $key,'Range' => 'bytes=2-8']);
        $result = $this->signedHttp($get, 'GET');
        self::assertSame(206, $result['status']);
        self::assertSame(substr($text, 2, 7), $result['body']);
        self::assertSame('bytes 2-8/' . strlen($text), $result['headers']['content-range']);
        $unsigned = $this->rawHttp($this->s3Config->publicEndpoint . '/' . $this->s3Config->bucketStorage . '/' . $key, 'GET');
        self::assertContains($unsigned['status'], [401,403,404]);
        $list = $this->rawHttp($this->s3Config->publicEndpoint . '/' . $this->s3Config->bucketStorage . '?list-type=2', 'GET');
        self::assertContains($list['status'], [401,403,404]);
    }

    public function testMultipartCanBeListedResumedAndCompletedWithActualChecksum(): void
    {
        $key = $this->ownKey('resume.bin');
        $bucket = $this->s3Config->bucketStorage;
        $client = $this->clients->internal();
        $id = (string) $client->createMultipartUpload(['Bucket' => $bucket,'Key' => $key,'ChecksumAlgorithm' => 'SHA256'])['UploadId'];
        $parts = [];
        $digest = hash_init('sha256');
        $length = 0;
        $completed = false;
        try {
            foreach ([str_repeat('A', 5 * 1024 * 1024),str_repeat('B', 12345)] as $index => $body) {
                $number = $index + 1;
                $sha = base64_encode(hash('sha256', $body, true));
                hash_update($digest, $body);
                $length += strlen($body);
                if ($number === 2) {
                    $listed = $client->listParts(['Bucket' => $bucket,'Key' => $key,'UploadId' => $id]);
                    self::assertCount(1, $listed['Parts']);
                    self::assertSame(1, (int) $listed['Parts'][0]['PartNumber']);
                }
                $command = $this->clients->public()->getCommand('UploadPart', ['Bucket' => $bucket,'Key' => $key,'UploadId' => $id,
                    'PartNumber' => $number,'ContentLength' => strlen($body),'ChecksumSHA256' => $sha]);
                $response = $this->signedHttp($command, 'PUT', $body);
                self::assertSame(200, $response['status']);
                $parts[] = ['PartNumber' => $number,'ETag' => $response['headers']['etag'],'ChecksumSHA256' => $sha];
            }
            $client->completeMultipartUpload(['Bucket' => $bucket,'Key' => $key,'UploadId' => $id,'MultipartUpload' => ['Parts' => $parts]]);
            $completed = true;
            self::assertSame(['sha256' => hash_final($digest),'sizeBytes' => $length], $this->objects->verify($bucket, $key, $length));
        } finally {
            if (!$completed) {
                $client->abortMultipartUpload(['Bucket' => $bucket,'Key' => $key,'UploadId' => $id]);
            }
        }
    }

    public function testAbortDoesNotPublishAnIncompleteObject(): void
    {
        $key = $this->ownKey('abort.bin');
        $bucket = $this->s3Config->bucketStorage;
        $client = $this->clients->internal();
        $id = (string) $client->createMultipartUpload(['Bucket' => $bucket,'Key' => $key])['UploadId'];
        $client->uploadPart(['Bucket' => $bucket,'Key' => $key,'UploadId' => $id,'PartNumber' => 1,'Body' => 'partial']);
        $client->abortMultipartUpload(['Bucket' => $bucket,'Key' => $key,'UploadId' => $id]);
        self::assertNull($this->objects->head($bucket, $key));
        $uploads = $client->listMultipartUploads(['Bucket' => $bucket,'Prefix' => $key]);
        self::assertSame([], $uploads['Uploads'] ?? []);
    }

    public function testChangingStagingCannotChangeThePublishedCopy(): void
    {
        $stage = $this->ownKey('staging.txt');
        $final = $this->ownKey('final.txt');
        $bucket = $this->s3Config->bucketStorage;
        foreach (['first version','replacement'] as $index => $value) {
            $stream = tmpfile();
            fwrite($stream, $value);
            rewind($stream);
            $this->objects->put($bucket, $stage, $stream);
            fclose($stream);
            if ($index === 0) {
                $this->objects->copy($bucket, $stage, $final);
            }
        }
        self::assertSame(hash('sha256', 'first version'), $this->objects->verify($bucket, $final)['sha256']);
        self::assertSame(hash('sha256', 'replacement'), $this->objects->verify($bucket, $stage)['sha256']);
    }

    public function testMissingObjectAndOversizeReadAreExplicitErrors(): void
    {
        $bucket = $this->s3Config->bucketStorage;
        $missing = $this->ownKey('missing');
        self::assertNull($this->objects->head($bucket, $missing));
        try {
            $this->objects->open($bucket, $missing);
            self::fail('Missing object was opened.');
        } catch (StorageError $error) {
            self::assertSame(404, $error->status);
            self::assertSame('object_missing', $error->reason);
        }
        $key = $this->ownKey('oversize');
        $stream = $this->dataStream(100);
        $this->objects->put($bucket, $key, $stream);
        fclose($stream);
        try {
            $this->objects->verify($bucket, $key, 99);
            self::fail('Oversize data passed verification.');
        } catch (StorageError $error) {
            self::assertSame('size_mismatch', $error->reason);
        }
    }

    public function testExpiredSignedUrlCannotReadAValidPrivateObject(): void
    {
        $key = $this->ownKey('expired');
        $stream = $this->dataStream(15);
        $bucket = $this->s3Config->bucketStorage;
        $this->objects->put($bucket, $key, $stream);
        fclose($stream);
        $command = $this->clients->public()->getCommand('GetObject', ['Bucket' => $bucket,'Key' => $key]);
        $signed = $this->clients->public()->createPresignedRequest($command, '+1 second');
        usleep(2200000);
        $response = $this->rawHttp((string) $signed->getUri(), 'GET');
        self::assertContains($response['status'], [401,403]);
    }
}
