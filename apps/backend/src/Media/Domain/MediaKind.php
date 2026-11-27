<?php

declare(strict_types=1);

namespace App\Media\Domain;

enum MediaKind: string
{
    case Audio = 'audio';
    case Voice = 'voice';
    case Video = 'video';
    case VideoNote = 'video_note';
    case Image = 'image';
    case Document = 'document';

    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    public function maxSizeBytes(): int
    {
        return match ($this) {
            self::Voice => 50 * 1024 * 1024,        // 50 MB
            self::VideoNote => 100 * 1024 * 1024,   // 100 MB
            self::Audio => 150 * 1024 * 1024,       // 150 MB
            self::Image => 25 * 1024 * 1024,        // 25 MB
            self::Video => 500 * 1024 * 1024,       // 500 MB
            self::Document => 100 * 1024 * 1024,    // 100 MB
        };
    }

    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::Voice => ['audio/ogg', 'audio/webm', 'audio/mp4', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/mpeg'],
            self::VideoNote => ['video/mp4', 'video/webm', 'video/quicktime'],
            self::Audio => ['audio/mpeg', 'audio/ogg', 'audio/webm', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/aac', 'audio/mp4'],
            self::Image => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'],
            self::Video => ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska'],
            self::Document => [
                'application/pdf',
                'text/plain',
                'text/csv',
                'application/json',
                'application/zip',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
        };
    }
}
