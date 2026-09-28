<?php

declare(strict_types=1);

namespace App\Core\Consent\Application;

use InvalidArgumentException;

final readonly class ConsentDefinition
{
    public function __construct(
        public string $code,
        public string $currentDocumentVersion,
        public string $currentDocumentSha256Hex,
        public bool $withdrawable,
    ) {
        if (!preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $code)
            || $currentDocumentVersion === ''
            || !preg_match('/^[a-f0-9]{64}$/D', $currentDocumentSha256Hex)) {
            throw new InvalidArgumentException('Invalid consent definition.');
        }
    }

    /** @return array{code: string, current_document_version: string, current_document_sha256: string, withdrawable: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'current_document_version' => $this->currentDocumentVersion,
            'current_document_sha256' => $this->currentDocumentSha256Hex,
            'withdrawable' => $this->withdrawable,
        ];
    }
}
