<?php

declare(strict_types=1);

namespace App\DeviceManagement\Infrastructure;

use App\Core\Audit\Application\AuditEventDefinition;
use Yiisoft\Definitions\Reference;

final class Configuration
{
    /** @return array<string,mixed> */
    public static function params(): array
    {
        $audit = [];
        foreach (
            [
                'create_classroom',
                'update_classroom',
                'archive_classroom', 'restore_classroom', 'cancel_assignment', 'retry_recovery',
                'create_device',
                'update_device',
                'device_enroll',
                'start_session',
                'end_session',
                'revoke_device'
            ] as $operation
        ) {
            $audit[] = new AuditEventDefinition('device_management.' . $operation, metadataFields: ['organizationId' => 'uuid']);
        }
        return [
            'auditEventDefinitions' => $audit,
            'personalDataContributors' => [Reference::to(PersonalDataContributor::class)],
        ];
    }
}
