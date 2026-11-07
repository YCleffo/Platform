<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\EducationError;
use App\Education\Application\FileStore;
use App\Education\Application\Input;
use App\Education\Application\StoredFile;
use Psr\Http\Message\UploadedFileInterface;

final readonly class PrivateFileStore implements FileStore
{
    public function __construct(private string $directory) {}

    public function store(UploadedFileInterface $upload, bool $avatar): StoredFile
    {
        $name = Input::text(basename(str_replace('\\', '/', $upload->getClientFilename() ?? 'file')), 240);
        $limit = $avatar ? 2 * 1024 * 1024 : 10 * 1024 * 1024;
        if ($upload->getError() !== UPLOAD_ERR_OK || ($upload->getSize() ?? 0) > $limit) {
            throw new EducationError(422, 'invalid_file');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new EducationError(503, 'file_store_unavailable');
        }
        $hex = bin2hex(random_bytes(16));
        $key = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20);
        $path = $this->directory . DIRECTORY_SEPARATOR . $key;
        $stream = $upload->getStream();
        $target = fopen($path, 'xb');
        if ($target === false) {
            throw new EducationError(503, 'file_store_unavailable');
        }
        $size = 0;
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (!$stream->eof()) {
                $chunk = $stream->read(65536);
                $size += strlen($chunk);
                if ($size > $limit) {
                    throw new EducationError(422, 'invalid_file');
                }
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new EducationError(503, 'file_store_unavailable');
                }
            }
        } catch (\Throwable $error) {
            fclose($target);
            unlink($path);
            throw $error;
        }
        fclose($target);
        chmod($path, 0600);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $allowed = $avatar ? ['image/png', 'image/jpeg', 'image/webp']
            : ['image/png', 'image/jpeg', 'image/webp', 'application/pdf', 'text/plain'];
        if ($size === 0 || !is_string($mime) || !in_array($mime, $allowed, true)) {
            unlink($path);
            throw new EducationError(422, 'invalid_file');
        }
        if ($avatar) {
            $image = getimagesize($path);
            if ($image === false || $image[0] * $image[1] > 20000000) {
                unlink($path);
                throw new EducationError(422, 'invalid_file');
            }
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            unlink($path);
            throw new EducationError(503, 'file_store_unavailable');
        }
        return new StoredFile($key, $name, $mime, $size, $hash);
    }

    public function path(string $key): string
    {
        Input::uuid($key);
        $path = $this->directory . DIRECTORY_SEPARATOR . $key;
        if (!is_file($path) || is_link($path)) {
            throw new EducationError(404, 'file_not_found');
        }
        return $path;
    }

    public function remove(string $key): void
    {
        Input::uuid($key);
        $path = $this->directory . DIRECTORY_SEPARATOR . $key;
        if (is_file($path) && !unlink($path)) {
            throw new EducationError(503, 'file_store_unavailable');
        }
    }

    public function stageRemoval(string $key): ?string
    {
        Input::uuid($key);
        $path = $this->directory . DIRECTORY_SEPARATOR . $key;
        if (!is_file($path)) {
            return null;
        }
        $trash = $this->directory . DIRECTORY_SEPARATOR . '.trash';
        if (!is_dir($trash) && !mkdir($trash, 0700, true) && !is_dir($trash)) {
            throw new EducationError(503, 'file_store_unavailable');
        }
        $staged = $trash . DIRECTORY_SEPARATOR . $key;
        if (!rename($path, $staged)) {
            throw new EducationError(503, 'file_store_unavailable');
        }
        return $staged;
    }

    public function restoreRemoval(?string $stagedPath): void
    {
        if ($stagedPath === null || !is_file($stagedPath)) {
            return;
        }
        $key = basename($stagedPath);
        Input::uuid($key);
        if (!rename($stagedPath, $this->directory . DIRECTORY_SEPARATOR . $key)) {
            throw new EducationError(503, 'file_store_unavailable');
        }
    }

    public function purgeRemoval(?string $stagedPath): void
    {
        if ($stagedPath !== null && is_file($stagedPath) && !unlink($stagedPath)) {
            throw new EducationError(503, 'file_store_unavailable');
        }
    }
}
