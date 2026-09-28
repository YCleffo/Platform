<?php

declare(strict_types=1);

namespace App\Core\Audit\Application;

use InvalidArgumentException;

/** Final shared boundary for every AuditWriter caller, including future modules. */
final class AuditPayloadPolicy
{
    private const MAX_BYTES = 4096;
    private const MAX_DEPTH = 4;
    private const MAX_ITEMS = 40;
    private const MAX_STRING_BYTES = 512;
    private const FORBIDDEN = [
        'password', 'passwd', 'secret', 'token', 'credential', 'recoverycode',
        'privatekey', 'authorization', 'cookie', 'documentcontent', 'rawbody',
        'requestbody', 'responsebody', 'medical', 'health', 'birthdate',
        'fullname',
    ];

    public function __construct(private AuditEventDefinitionRegistry $definitions) {}

    public function assertAllowed(WriteAuditEventCommand $command): void
    {
        if (str_starts_with($command->action, 'privacy.')
            && ($command->beforeData !== null || $command->afterData !== null || $command->metadata !== [])) {
            throw new InvalidArgumentException('Privacy audit events may contain only typed resource evidence.');
        }
        $definition = $this->definitions->get($command->action);
        foreach ([
            [$command->beforeData, $definition?->beforeFields ?? []],
            [$command->afterData, $definition?->afterFields ?? []],
            [$command->metadata, $definition?->metadataFields ?? []],
        ] as [$payload, $fields]) {
            if ($payload === null) {
                continue;
            }
            $this->assertFields($payload, $fields, $definition?->maxDepth ?? 1);
            $this->assertNode($payload, 1);
            if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > ($definition?->maxPayloadBytes ?? self::MAX_BYTES)) {
                throw new InvalidArgumentException('Audit payload exceeds the size limit.');
            }
        }
    }

    /** @param array<array-key, mixed> $payload @param array<string, string> $fields */
    private function assertFields(array $payload, array $fields, int $maxDepth): void
    {
        foreach ($payload as $key => $value) {
            if (!is_string($key) || !isset($fields[$key])) {
                throw new InvalidArgumentException('Audit payload field is not defined for this action.');
            }
            if ($value === null) {
                continue;
            }
            $type = $fields[$key];
            $valid = match ($type) {
                'code' => is_string($value) && strlen($value) <= 120 && preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $value) === 1,
                'auth_method' => is_string($value) && strlen($value) <= 80 && preg_match('/^[a-z0-9_]+(?:\+[a-z0-9_]+)?$/D', $value) === 1,
                'uuid' => is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $value) === 1,
                'digest' => is_string($value) && preg_match('/^[a-f0-9]{32,128}$/Di', $value) === 1,
                'bool' => is_bool($value),
                'int' => is_int($value) && $value >= 0,
                'timestamp' => is_string($value) && strlen($value) <= 48 && strtotime($value) !== false,
                'ip' => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false,
                'domain' => is_string($value) && strlen($value) <= 253 && preg_match('/^[a-zA-Z0-9.-]+$/D', $value) === 1,
                'path_list', 'code_list' => $maxDepth >= 2 && is_array($value) && array_is_list($value)
                    && count($value) <= 32 && $this->validCodes($value),
                default => false,
            };
            if (!$valid) {
                throw new InvalidArgumentException('Audit payload field has an unsafe value.');
            }
        }
    }

    /** @param array<array-key, mixed> $values */
    private function validCodes(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value) || strlen($value) > 120
                || preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/D', $value) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @param array<array-key, mixed> $node */
    private function assertNode(array $node, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || count($node) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Audit payload is too deep or wide.');
        }
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                if (strlen($key) > 64 || $key === '' || !preg_match('/^[a-zA-Z0-9._-]+$/D', $key)) {
                    throw new InvalidArgumentException('Invalid audit payload key.');
                }
                $normalized = strtolower(str_replace(['_', '-', '.'], '', $key));
                foreach (self::FORBIDDEN as $term) {
                    if (str_contains($normalized, $term)) {
                        throw new InvalidArgumentException('Sensitive audit payload key is forbidden.');
                    }
                }
                if ((str_contains($normalized, 'email') || str_contains($normalized, 'phone'))
                    && !is_bool($value) && $value !== null) {
                    throw new InvalidArgumentException('Contact values require an explicit audit definition.');
                }
            }
            if (is_array($value)) {
                $this->assertNode($value, $depth + 1);
            } elseif (is_string($value)) {
                if (strlen($value) > self::MAX_STRING_BYTES
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)
                    || preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value)) {
                    throw new InvalidArgumentException('Audit payload contains an unsafe value.');
                }
            } elseif (!is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
                throw new InvalidArgumentException('Audit payload contains an unsupported value.');
            }
        }
    }
}
