<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;

final readonly class DataSubjectRequestTypeRegistry
{
    /** @return array{scope:string,deadline:?string,requiredCapability:string,block:bool} */
    public function get(string $type): array
    {
        return match ($type) {
            'access' => ['scope' => 'privacy.request.submit', 'deadline' => 'access_response', 'requiredCapability' => 'privacy.data.access', 'block' => false],
            'processing_information' => ['scope' => 'privacy.request.submit', 'deadline' => 'processing_information_response', 'requiredCapability' => 'privacy.processing_information.response', 'block' => false],
            'correction' => ['scope' => 'privacy.correction.request', 'deadline' => null, 'requiredCapability' => 'privacy.data.correction', 'block' => true],
            'restriction' => ['scope' => 'privacy.processing_restriction.request', 'deadline' => null, 'requiredCapability' => 'privacy.processing.restriction', 'block' => true],
            'processing_termination' => ['scope' => 'privacy.processing_termination.request', 'deadline' => 'processing_termination', 'requiredCapability' => 'privacy.processing.termination', 'block' => true],
            'erasure' => ['scope' => 'privacy.erasure.request', 'deadline' => null, 'requiredCapability' => 'privacy.data.erasure', 'block' => true],
            'export' => ['scope' => 'privacy.export.request', 'deadline' => null, 'requiredCapability' => 'privacy.data.export', 'block' => false],
            'consent_withdrawal' => ['scope' => 'privacy.consent.withdraw', 'deadline' => null, 'requiredCapability' => 'privacy.consent.withdrawal', 'block' => false],
            default => throw new DomainException('Unknown data subject request type.'),
        };
    }
}
