<?php

declare(strict_types=1);

namespace App\Core\Privacy\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RetentionCandidate
{
    public function __construct(
        public string $subjectPersonId,
        public string $resourceType,
        public string $resourceId,
        public string $purposeCode,
        public string $retentionPolicyCode,
        public string $triggerCode,
        public ?DateTimeImmutable $triggeredAt,
        public string $contributorCode,
    ) {
        foreach ([$resourceType, $purposeCode, $retentionPolicyCode, $triggerCode, $contributorCode] as $code) {
            MachineCode::assert($code);
        }
        foreach ([$subjectPersonId, $resourceId] as $id) {
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $id) !== 1) {
                throw new InvalidArgumentException('Invalid retention identifier.');
            }
        }
    }
}
