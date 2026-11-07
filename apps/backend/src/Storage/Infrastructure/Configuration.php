<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Audit\Application\AuditEventDefinition;

final class Configuration
{
    /** @return array<string,mixed> */
    public static function params(): array
    {
        $audit = [];
        foreach (['upload'] as $operation) {
            $audit[] = new AuditEventDefinition('storage.' . $operation, metadataFields: ['organizationId' => 'uuid']);
        }
        return ['auditEventDefinitions' => $audit];
    }
}
