<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\{EducationError, FileStore, Input, StoredFile};
use App\Storage\Infrastructure\{S3Config, S3ClientFactory};
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Education objects share the S3 infrastructure; only bounded, automatically deleted verification spools are local. */
final readonly class S3FileStore implements FileStore
{
    public function __construct(private S3Config $config, private S3ClientFactory $clients, private ConnectionInterface $db) {}

    public static function limit(bool $avatar): int { return $avatar ? 2097152 : 10485760; }
    public static function objectKey(string $key): string { return 'objects/' . Input::uuid($key); }
    public static function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    public function store(UploadedFileInterface $upload, bool $avatar): StoredFile
    {
        if ($upload->getError() !== UPLOAD_ERR_OK || ($upload->getSize() ?? 0) > self::limit($avatar)) throw new EducationError(422, 'invalid_file');
        $source = $upload->getStream(); $spool = tmpfile();
        if ($spool === false) throw new EducationError(503, 'file_store_unavailable');
        try {
            if ($source->isSeekable()) $source->rewind();
            $size = 0;
            while (!$source->eof()) {
                $chunk = $source->read(65536); $size += strlen($chunk);
                if ($size > self::limit($avatar)) throw new EducationError(422, 'invalid_file');
                if (fwrite($spool, $chunk) !== strlen($chunk)) throw new EducationError(503, 'file_store_unavailable');
            }
            $stored = $this->inspect($spool, $upload->getClientFilename() ?? 'file', $avatar, self::uuid());
            $this->clients->internal()->putObject(['Bucket'=>$this->config->bucketEducation,'Key'=>self::objectKey($stored->key),
                'Body'=>$spool,'ContentLength'=>$stored->size,'ContentType'=>$stored->mime,'Metadata'=>['sha256'=>$stored->sha256]]);
            $verified = $this->open($stored->key, $stored->size, $stored->sha256); fclose($verified);
            return $stored;
        } catch (EducationError $error) { throw $error; }
        catch (\Throwable) { throw new EducationError(503, 'file_store_unavailable'); }
        finally { fclose($spool); }
    }

    /** Server creates this key; browsers can replace staging bytes, never committed objects. */
    public function uploadRequest(string $stagingKey, int $size, string $sha256): array
    {
        $expires = new \DateTimeImmutable('+' . $this->config->presignTtlSeconds . ' seconds');
        try {
            $client = $this->clients->public();
            $command = $client->getCommand('PutObject', ['Bucket'=>$this->config->bucketEducation,'Key'=>$stagingKey,
                'ContentType'=>'application/octet-stream','ContentLength'=>$size,
                'ChecksumSHA256'=>base64_encode(hex2bin($sha256))]);
            $request = $client->createPresignedRequest($command, $expires);
            $headers = $request->getHeaders(); unset($headers['Host'], $headers['host'], $headers['Content-Length'], $headers['content-length']);
            return ['url'=>(string)$request->getUri(),'method'=>'PUT','headers'=>(object)array_map(static fn(array $v):string=>implode(',',$v),$headers),'expiresAt'=>$expires->format(DATE_ATOM)];
        } catch (\Throwable) { throw new EducationError(503, 'file_store_unavailable'); }
    }

    /** Checks the actual object, including MIME/image validation, before copying into an immutable final key. */
    public function publish(string $stagingKey, string $finalKey, string $name, bool $avatar, int $size, string $sha256): StoredFile
    {
        $spool = $this->verifiedObject($stagingKey, $size, $sha256, true);
        try {
            $stored = $this->inspect($spool, $name, $avatar, Input::uuid($finalKey));
            $this->clients->internal()->copyObject(['Bucket'=>$this->config->bucketEducation,'Key'=>self::objectKey($finalKey),
                'CopySource'=>$this->config->bucketEducation.'/'.$stagingKey,'MetadataDirective'=>'REPLACE',
                'ContentType'=>$stored->mime,'Metadata'=>['sha256'=>$stored->sha256]]);
            $verified = $this->open($stored->key, $stored->size, $stored->sha256); fclose($verified);
            return $stored;
        } catch (EducationError $error) { throw $error; }
        catch (\Throwable) { throw new EducationError(503, 'file_store_unavailable'); }
        finally { fclose($spool); }
    }

    public function open(string $key, int $expectedSize, string $expectedSha256)
    {
        return $this->verifiedObject(self::objectKey($key), $expectedSize, $expectedSha256, false);
    }

    public function downloadRequest(string $key, string $name, string $mime, int $size): array
    {
        try {
            $head = $this->clients->internal()->headObject(['Bucket'=>$this->config->bucketEducation,'Key'=>self::objectKey($key)]);
            if ((int)$head['ContentLength'] !== $size) throw new EducationError(503, 'file_integrity_failed');
            $expires = new \DateTimeImmutable('+' . $this->config->presignTtlSeconds . ' seconds'); $client=$this->clients->public();
            $request=$client->createPresignedRequest($client->getCommand('GetObject',['Bucket'=>$this->config->bucketEducation,
                'Key'=>self::objectKey($key),'ResponseContentType'=>$mime,
                'ResponseContentDisposition'=>"attachment; filename=\"download\"; filename*=UTF-8''".rawurlencode($name)]),$expires);
            return ['url'=>(string)$request->getUri(),'method'=>'GET','headers'=>(object)[],'expiresAt'=>$expires->format(DATE_ATOM)];
        } catch (EducationError $error) { throw $error; }
        catch (\Throwable) { throw new EducationError(503, 'file_store_unavailable'); }
    }

    public function remove(string $key): void { $this->queue(self::objectKey($key)); }
    public function stageRemoval(string $key): ?string { Input::uuid($key); return $key; }
    public function restoreRemoval(?string $stagedPath): void { if ($stagedPath !== null) Input::uuid($stagedPath); }
    public function purgeRemoval(?string $stagedPath): void { if ($stagedPath !== null) $this->remove($stagedPath); }
    public function queue(string $objectKey): void
    {
        if (!preg_match('~^(objects/[0-9a-f-]{36}|staging/[0-9a-f-]{36}/[0-9a-f]{32})$~D',$objectKey)) throw new \InvalidArgumentException('Invalid Education object key.');
        $this->db->createCommand('INSERT INTO education.object_cleanup(object_key) VALUES(:key)
            ON CONFLICT(object_key) DO UPDATE SET completed_at=NULL', [':key'=>$objectKey])->execute();
    }

    private function inspect($stream, string $name, bool $avatar, string $key): StoredFile
    {
        $size = (int)(fstat($stream)['size'] ?? 0);
        if ($size < 1 || $size > self::limit($avatar)) throw new EducationError(422, 'invalid_file');
        rewind($stream); $sample = fread($stream, min($size,262144));
        if (!is_string($sample)) throw new EducationError(503, 'file_store_unavailable');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($sample);
        $allowed=$avatar?['image/png','image/jpeg','image/webp']:['image/png','image/jpeg','image/webp','application/pdf','text/plain'];
        if (!is_string($mime)||!in_array($mime,$allowed,true)) throw new EducationError(422,'invalid_file');
        if ($avatar) {
            rewind($stream); $image=getimagesizefromstring(stream_get_contents($stream));
            if ($image===false || $image[0]<1 || $image[1]<1 || $image[0]*$image[1]>20000000) throw new EducationError(422,'invalid_file');
        }
        rewind($stream); $hash=hash_init('sha256'); hash_update_stream($hash,$stream); $digest=hash_final($hash); rewind($stream);
        $name=Input::text(basename(str_replace('\\','/',$name)),240);
        return new StoredFile($key,$name,$mime,$size,$digest);
    }

    private function verifiedObject(string $objectKey, int $expectedSize, string $sha256, bool $upload)
    {
        if ($expectedSize<1 || $expectedSize>self::limit(false) || !preg_match('/^[a-f0-9]{64}$/D',$sha256)) throw new EducationError(422,'invalid_file');
        $target=tmpfile(); if ($target===false) throw new EducationError(503,'file_store_unavailable');
        $body=null;
        try {
            $result=$this->clients->internal()->getObject(['Bucket'=>$this->config->bucketEducation,'Key'=>$objectKey,'@http'=>['stream'=>true]]);
            $body=$result['Body']; $hash=hash_init('sha256'); $size=0;
            while (!$body->eof()) {
                $chunk=$body->read(65536); $size+=strlen($chunk);
                if ($size>$expectedSize || fwrite($target,$chunk)!==strlen($chunk)) throw new EducationError($upload?422:503,$upload?'size_mismatch':'file_integrity_failed');
                hash_update($hash,$chunk);
            }
            if ($size!==$expectedSize || !hash_equals($sha256,hash_final($hash))) throw new EducationError($upload?422:503,$upload?'checksum_mismatch':'file_integrity_failed');
            rewind($target); return $target;
        } catch (\Throwable $error) {
            fclose($target); if ($error instanceof EducationError) throw $error;
            throw new EducationError(503,'file_store_unavailable');
        } finally { $body?->close(); }
    }
}
