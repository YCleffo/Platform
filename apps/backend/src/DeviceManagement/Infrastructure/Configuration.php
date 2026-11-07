<?php

declare(strict_types=1);

namespace App\DeviceManagement\Infrastructure;

use App\Core\Audit\Application\AuditEventDefinition;

final class Configuration
{
    /** @return array<string,mixed> */
    public static function params(): array
    {
        $audit = [];
        foreach (['create_classroom', 'create_device', 'device_enroll', 'start_session', 'end_session',
            'revoke_device'] as $operation) {
            $audit[] = new AuditEventDefinition('device_management.' . $operation, metadataFields: ['organizationId' => 'uuid']);
        }
        return ['auditEventDefinitions' => $audit];
    }
}
