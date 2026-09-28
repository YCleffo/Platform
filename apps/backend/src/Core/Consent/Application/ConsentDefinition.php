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
        public string $purposeCode,
    ) {
        if (!preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $code)
            || $currentDocumentVersion === ''
            || !preg_match('/^[a-f0-9]{64}$/D', $currentDocumentSha256Hex)
            || !preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $purposeCode)) {
            throw new InvalidArgumentException('Invalid consent definition.');
        }
    }

    /** @return array{code: string, purpose_code: string, current_document_version: string, current_document_sha256: string, withdrawable: bool, representative_allowed: bool} */
    public function toArray(bool $representativeAllowed): array
    {
        return [
            'code' => $this->code,
            'purpose_code' => $this->purposeCode,
            'current_document_version' => $this->currentDocumentVersion,
            'current_document_sha256' => $this->currentDocumentSha256Hex,
            'withdrawable' => $this->withdrawable,
            'representative_allowed' => $representativeAllowed,
        ];
    }
}
