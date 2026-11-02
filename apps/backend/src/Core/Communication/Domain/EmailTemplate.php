<?php

declare(strict_types=1);

namespace App\Core\Communication\Domain;

use App\Core\Internationalization\Domain\Locale;
use InvalidArgumentException;

final readonly class EmailTemplate
{
    /**
     * @param list<string> $variables
     */
    public function __construct(
        public string $code,
        public string $locale,
        public string $subject,
        public string $htmlBody,
        public string $textBody,
        public array $variables,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $code) !== 1) {
            throw new InvalidArgumentException('Email template code is invalid.');
        }
        new Locale($locale);
        if (trim($subject) === '' || trim($htmlBody) === '' || trim($textBody) === '') {
            throw new InvalidArgumentException('Rendered email template is incomplete.');
        }
    }
}
