<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;

final readonly class DataSubjectRequestTypeRegistry
{
    /** @return array{scope:string,deadline:?string,completion:bool,block:bool} */
    public function get(string $type): array
    {
        return match ($type) {
            'access' => ['scope' => 'privacy.request.submit', 'deadline' => 'access_response', 'completion' => false, 'block' => false],
            'processing_information' => ['scope' => 'privacy.request.submit', 'deadline' => 'processing_information_response', 'completion' => false, 'block' => false],
            'correction' => ['scope' => 'privacy.correction.request', 'deadline' => null, 'completion' => false, 'block' => true],
            'restriction' => ['scope' => 'privacy.processing_restriction.request', 'deadline' => null, 'completion' => false, 'block' => true],
            'processing_termination' => ['scope' => 'privacy.processing_termination.request', 'deadline' => 'processing_termination', 'completion' => false, 'block' => true],
            'erasure' => ['scope' => 'privacy.erasure.request', 'deadline' => null, 'completion' => false, 'block' => true],
            'export' => ['scope' => 'privacy.export.request', 'deadline' => null, 'completion' => false, 'block' => false],
            'consent_withdrawal' => ['scope' => 'privacy.consent.withdraw', 'deadline' => null, 'completion' => false, 'block' => false],
            default => throw new DomainException('Unknown data subject request type.'),
        };
    }
}
