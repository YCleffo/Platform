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
        public ?string $documentTitle = null,
        public ?string $documentText = null,
    ) {
        if (!preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $code)
            || $currentDocumentVersion === ''
            || !preg_match('/^[a-f0-9]{64}$/D', $currentDocumentSha256Hex)
            || !preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $purposeCode)) {
            throw new InvalidArgumentException('Invalid consent definition.');
        }
        if (($documentTitle === null) !== ($documentText === null)
            || ($documentText !== null && (trim($documentTitle ?? '') === '' || trim($documentText) === ''
                || !hash_equals($currentDocumentSha256Hex, hash('sha256', $documentText))))) {
            throw new InvalidArgumentException('Consent document must match its registered SHA-256.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(bool $representativeAllowed): array
    {
        return [
            'code' => $this->code,
            'purpose_code' => $this->purposeCode,
            'current_document_version' => $this->currentDocumentVersion,
            'current_document_sha256' => $this->currentDocumentSha256Hex,
            'withdrawable' => $this->withdrawable,
            'representative_allowed' => $representativeAllowed,
            'document' => $this->documentText === null ? null : ['title' => $this->documentTitle, 'text' => $this->documentText],
        ];
    }
}
