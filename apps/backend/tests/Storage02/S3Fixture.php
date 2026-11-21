<?php

declare(strict_types=1);

namespace App\Tests\Storage02;

use App\Storage\Infrastructure\S3ClientFactory;
use App\Storage\Infrastructure\S3Config;
use App\Storage\Infrastructure\S3Objects;
use Aws\CommandInterface;

/** The explicit runtime and bucket checks apply before any object is written or deleted. */
trait S3Fixture
{
    private S3Config $s3Config;
    private S3ClientFactory $clients;
    private S3Objects $objects;
    /** @var list<string> */
    private array $ownedKeys = [];
    private string $testPrefix;

    private function startS3Fixture(): void
    {
        $this->s3Config = new S3Config();
        IsolatedS3Runtime::assertConfigured($this->s3Config);
        $this->clients = new S3ClientFactory($this->s3Config);
        $this->objects = new S3Objects($this->clients, $this->s3Config);
        $this->testPrefix = 'qa/' . bin2hex(random_bytes(12)) . '/';
        $this->clients->internal()->headBucket(['Bucket' => $this->s3Config->bucketStorage]);
    }

    private function ownKey(string $suffix): string
    {
        $key = $this->testPrefix . $suffix;
        $this->ownedKeys[] = $key;
        return $key;
    }

    private function finishS3Fixture(): void
    {
        foreach (array_unique($this->ownedKeys) as $key) {
            $this->objects->delete($this->s3Config->bucketStorage, $key);
        }
    }

    /** @return resource */
    private function dataStream(int $size)
    {
        $stream = tmpfile();
        self::assertIsResource($stream);
        $chunk = str_repeat('storage02-stream-', 4096);
        for ($remaining = $size; $remaining > 0; $remaining -= $length) {
            $length = min(strlen($chunk), $remaining);
            self::assertSame($length, fwrite($stream, substr($chunk, 0, $length)));
        }
        rewind($stream);
        return $stream;
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    private function signedHttp(CommandInterface $command, string $method, ?string $body = null, string $expires = '+60 seconds'): array
    {
        $request = $this->clients->public()->createPresignedRequest($command, $expires);
        $headers = array_map(static fn(array $values): string => implode(',', $values), $request->getHeaders());
        // SDK SigV4 intentionally removes Range from presigned headers. The consumer
        // sends the desired range separately, as the Agent's resumable GET does.
        if ($command->getName() === 'GetObject' && isset($command['Range'])) {
            $headers['Range'] = $command['Range'];
        }
        return $this->rawHttp((string) $request->getUri(), $method, $body, $headers);
    }

    /** @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>} */
    private function rawHttp(string $url, string $method, ?string $body = null, array $headers = []): array
    {
        $public = parse_url($this->s3Config->publicEndpoint);
        $internal = parse_url($this->s3Config->internalEndpoint);
        self::assertIsArray($public);
        self::assertIsArray($internal);
        $received = [];
        $curl = curl_init($url);
        // Docker reaches the exact public Host through the isolated service address.
        // URL, Host and SigV4 query remain byte-for-byte as signed for the Windows client.
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECT_TO => [($public['host'] . ':' . ($public['port'] ?? 80) . ':' . $internal['host'] . ':' . ($internal['port'] ?? 80))],
            CURLOPT_HTTPHEADER => array_map(
                static fn(string $key, string $value): string => $key . ': ' . $value,
                array_keys($headers),
                array_values($headers),
            ),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $bytes = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        self::assertIsString($bytes, 'The isolated object request must complete.');
        return ['status' => $status, 'body' => $bytes, 'headers' => $received];
    }
}
