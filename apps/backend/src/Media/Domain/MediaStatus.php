<?php

declare(strict_types=1);

namespace App\Media\Domain;

enum MediaStatus: string
{
    case Reserved = 'RESERVED';
    case Uploading = 'UPLOADING';
    case Uploaded = 'UPLOADED';
    case Processing = 'PROCESSING';
    case Ready = 'READY';
    case Failed = 'FAILED';
    case Quarantined = 'QUARANTINED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ready, self::Failed, self::Quarantined, self::Expired, self::Cancelled => true,
            default => false,
        };
    }
}
