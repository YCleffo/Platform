<?php

declare(strict_types=1);

namespace App\Core\Audit\Application;

use InvalidArgumentException;

/** Each field has a narrow value type; unknown keys and nested snapshots are denied. */
final readonly class AuditEventDefinition
{
    /**
     * @param array<string, string> $metadataFields
     * @param array<string, string> $beforeFields
     * @param array<string, string> $afterFields
     */
    public function __construct(
        public string $action,
        public array $metadataFields = [],
        public array $beforeFields = [],
        public array $afterFields = [],
        public int $maxPayloadBytes = 2048,
        public int $maxDepth = 2,
    ) {
        if (preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $action) !== 1
            || $maxPayloadBytes < 1 || $maxPayloadBytes > 4096 || $maxDepth < 1 || $maxDepth > 4) {
            throw new InvalidArgumentException('Invalid audit event definition.');
        }
        foreach ([$metadataFields, $beforeFields, $afterFields] as $fields) {
            foreach ($fields as $key => $type) {
                if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/D', $key) !== 1
                    || !in_array($type, ['code', 'auth_method', 'path_list', 'code_list', 'uuid', 'digest', 'bool', 'int', 'timestamp', 'ip', 'domain'], true)) {
                    throw new InvalidArgumentException('Invalid audit field definition.');
                }
            }
        }
    }
}
