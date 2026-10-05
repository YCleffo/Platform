<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Consent\Application\ConsentDefinition;
use App\Core\Privacy\Application\CrossBorderTransferDefinition;
use App\Core\Privacy\Application\DataRecipientDefinition;
use App\Core\Privacy\Application\DistributionConsentDefinition;
use App\Core\Privacy\Application\ProcessingInformationDefinition;
use App\Core\Privacy\Application\ProcessingRoleDefinition;
use App\Core\Privacy\Application\ProcessorDefinition;
use App\Core\Privacy\Domain\DataCategoryDefinition;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Domain\SubjectCategoryDefinition;
use DateTimeImmutable;
use ReflectionClass;
use RuntimeException;
use Throwable;

/** A trusted deployment JSON artifact; no legal values are embedded in application code. */
final class PrivacyDeploymentConfigLoader
{
    private const DEFINITIONS = [
        'consentDefinitions' => ConsentDefinition::class,
        'privacyDistributionConsentDefinitions' => DistributionConsentDefinition::class,
        'processingPurposeDefinitions' => ProcessingPurposeDefinition::class,
        'dataCategoryDefinitions' => DataCategoryDefinition::class,
        'subjectCategoryDefinitions' => SubjectCategoryDefinition::class,
        'retentionPolicyDefinitions' => RetentionPolicyDefinition::class,
        'privacyProcessingRoleDefinitions' => ProcessingRoleDefinition::class,
        'privacyProcessingInformationDefinitions' => ProcessingInformationDefinition::class,
        'privacyProcessorDefinitions' => ProcessorDefinition::class,
        'privacyRecipientDefinitions' => DataRecipientDefinition::class,
        'privacyTransferDefinitions' => CrossBorderTransferDefinition::class,
    ];
    private const OBJECTS = [
        'privacyBusinessCalendar', 'privacyOperatorIdentity', 'privacyOperatorNotification',
        'privacySecurityReferences', 'privacyDataResidency', 'privacyDestructionEvidence',
        'privacyLegalEvidence', 'privacyIncidentDeadlines', 'privacyCleanup',
    ];

    /** @return array<string, mixed> */
    public function load(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('PRIVACY_CONFIG_FILE is missing or unreadable.');
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Invalid deployment privacy JSON.', 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)
            || array_diff(array_keys($data), [...array_keys(self::DEFINITIONS), ...self::OBJECTS]) !== []) {
            throw new RuntimeException('Invalid deployment privacy configuration keys.');
        }
        $result = [];
        foreach (self::DEFINITIONS as $key => $class) {
            if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) {
                throw new RuntimeException("Invalid configuration path {$key}: required definition list is missing.");
            }
            $result[$key] = [];
            $seen = [];
            foreach ($data[$key] as $index => $row) {
                $path = "{$key}[{$index}]";
                if ($class === ProcessingPurposeDefinition::class && is_array($row)) {
                    if (!array_key_exists('legalBasisCode', $row) || !is_string($row['legalBasisCode'])
                        || trim($row['legalBasisCode']) === '') {
                        throw new RuntimeException("Invalid configuration path {$path}.legalBasisCode: legal basis is required.");
                    }
                    if (!array_key_exists('description', $row)) {
                        throw new RuntimeException("Invalid configuration path {$path}.description: purpose description is required.");
                    }
                }
                $object = self::hydrate($class, $row, $path);
                $identity = property_exists($object, 'code') ? $object->code
                    : (property_exists($object, 'purposeCode') ? $object->purposeCode : null);
                if ($identity !== null && isset($seen[$identity])) {
                    throw new RuntimeException("Invalid configuration path {$key}[{$index}]: duplicate code {$identity}.");
                }
                if ($identity !== null) { $seen[$identity] = true; }
                $result[$key][] = $object;
            }
        }
        foreach (self::OBJECTS as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])
                || ($data[$key] !== [] && array_is_list($data[$key]))) {
                throw new RuntimeException('Privacy evidence section is missing: ' . $key);
            }
            $result[$key] = $data[$key];
        }
        $calendar = $result['privacyBusinessCalendar'];
        foreach (['coverageFrom', 'coverageUntil', 'sourceReference', 'verifiedAt'] as $key) {
            if (!is_string($calendar[$key] ?? null)) {
                throw new RuntimeException('Invalid business calendar field: ' . $key);
            }
        }
        foreach (['weekends', 'nonWorkingDates', 'workingOverrideDates'] as $key) {
            if (!is_array($calendar[$key] ?? null) || !array_is_list($calendar[$key])) {
                throw new RuntimeException('Invalid business calendar list: ' . $key);
            }
        }
        new ConfiguredBusinessCalendar($calendar['coverageFrom'], $calendar['coverageUntil'],
            $calendar['weekends'], $calendar['nonWorkingDates'], $calendar['workingOverrideDates'],
            $calendar['sourceReference'], $calendar['verifiedAt']);
        if (!is_bool($result['privacyOperatorNotification']['required'] ?? null)
            || !is_array($result['privacyOperatorNotification']['registeredPurposes'] ?? null)
            || !array_is_list($result['privacyOperatorNotification']['registeredPurposes'])) {
            throw new RuntimeException('Invalid operator notification format.');
        }
        $codes = static fn(array $objects, string $property): array => array_map(
            static fn(object $object): string => $object->$property, $objects);
        $purposes = $codes($result['processingPurposeDefinitions'], 'code');
        $categories = $codes($result['dataCategoryDefinitions'], 'code');
        $subjects = $codes($result['subjectCategoryDefinitions'], 'code');
        $policies = $codes($result['retentionPolicyDefinitions'], 'code');
        $roles = $codes($result['privacyProcessingRoleDefinitions'], 'purposeCode');
        $information = $codes($result['privacyProcessingInformationDefinitions'], 'purposeCode');
        foreach ($result['processingPurposeDefinitions'] as $purpose) {
            $path = 'processingPurposeDefinitions[' . array_search($purpose, $result['processingPurposeDefinitions'], true) . ']';
            if (trim($purpose->description) === '') {
                throw new RuntimeException("Invalid configuration path {$path}.description: purpose description is required.");
            }
            if ($purpose->legalBasisCode === '' || !in_array($purpose->legalBasisCode,
                ['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests'], true)) {
                throw new RuntimeException("Invalid configuration path {$path}.legalBasisCode: unsupported legal basis.");
            }
            if (array_diff($purpose->dataCategories, $categories) !== []) {
                throw new RuntimeException("Invalid configuration path {$path}.dataCategories: unknown data category.");
            }
            if (array_diff($purpose->subjectCategories, $subjects) !== []) {
                throw new RuntimeException("Invalid configuration path {$path}.subjectCategories: unknown subject category.");
            }
            if (!in_array($purpose->retentionPolicyCode, $policies, true)) {
                throw new RuntimeException("Invalid configuration path {$path}.retentionPolicyCode: unknown retention policy.");
            }
            if (!in_array($purpose->code, $roles, true)) {
                throw new RuntimeException("Invalid configuration path {$path}.processingRole: processing role definition is missing.");
            }
            if (!in_array($purpose->code, $information, true)) {
                throw new RuntimeException("Invalid configuration path {$path}: processing-information definition is missing.");
            }
        }
        foreach ($result['privacyProcessingRoleDefinitions'] as $index => $role) {
            if (!in_array($role->purposeCode, $purposes, true)) {
                throw new RuntimeException("Invalid configuration path privacyProcessingRoleDefinitions[{$index}].purposeCode: unknown purpose.");
            }
            if (array_diff($role->dataCategories, $categories) !== []) {
                throw new RuntimeException("Invalid configuration path privacyProcessingRoleDefinitions[{$index}].dataCategories: unknown category.");
            }
        }
        foreach (['privacyProcessorDefinitions', 'privacyRecipientDefinitions'] as $key) {
            foreach ($result[$key] as $index => $definition) {
                if (array_diff($definition->purposes, $purposes) !== [] || array_diff($definition->dataCategories, $categories) !== []) {
                    throw new RuntimeException("Invalid configuration path {$key}[{$index}]: unknown purpose or data category.");
                }
            }
        }
        foreach ($result['privacyTransferDefinitions'] as $index => $transfer) {
            if (!in_array($transfer->providerCode, array_merge($codes($result['privacyProcessorDefinitions'], 'code'), $codes($result['privacyRecipientDefinitions'], 'code')), true)
                || !in_array($transfer->purposeCode, $purposes, true) || array_diff($transfer->dataCategories, $categories) !== []) {
                throw new RuntimeException("Invalid configuration path privacyTransferDefinitions[{$index}]: unknown provider, purpose, or data category.");
            }
        }
        foreach (['consentDefinitions', 'privacyDistributionConsentDefinitions'] as $key) {
            foreach ($result[$key] as $index => $definition) {
                if (!in_array($definition->purposeCode, $purposes, true)) {
                    throw new RuntimeException("Invalid configuration path {$key}[{$index}].purposeCode: unknown purpose.");
                }
                if ($key === 'privacyDistributionConsentDefinitions'
                    && (array_diff($definition->allowedCategories, $categories) !== []
                        || $result['processingPurposeDefinitions'][array_search($definition->purposeCode, $purposes, true)]->distributionAllowed !== true)) {
                    throw new RuntimeException("Invalid configuration path {$key}[{$index}]: distribution is not allowed for the purpose or category is unknown.");
                }
            }
        }
        self::assertNoPlaceholders($data);
        return $result;
    }

    private static function hydrate(string $class, mixed $row, string $path): object
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new RuntimeException("Invalid configuration path {$path}: definition must be an object.");
        }
        $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
        $allowed = array_map(static fn($parameter): string => $parameter->getName(), $parameters);
        if (array_diff(array_keys($row), $allowed) !== []) {
            throw new RuntimeException("Invalid configuration path {$path}: unknown field.");
        }
        foreach (['activeFrom', 'activeUntil', 'notificationSubmittedAt', 'regulatorReviewUntil'] as $field) {
            if (isset($row[$field])) {
                if (!is_string($row[$field]) || trim($row[$field]) === '') {
                    throw new RuntimeException("Invalid configuration path {$path}.{$field}: invalid date.");
                }
                $row[$field] = new DateTimeImmutable($row[$field]);
            }
        }
        try {
            return new $class(...$row);
        } catch (Throwable $exception) {
            throw new RuntimeException("Invalid configuration path {$path}: {$exception->getMessage()}", 0, $exception);
        }
    }

    private static function assertNoPlaceholders(mixed $value, string $path = 'root'): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) { self::assertNoPlaceholders($child, $path . '.' . $key); }
            return;
        }
        if (is_string($value) && preg_match('/(?:TODO|CHANGE_ME|REPLACE_ME|EXAMPLE_ONLY|<[^>]+>|^$)/i', trim($value)) === 1) {
            throw new RuntimeException("Invalid configuration path {$path}: unresolved template placeholder.");
        }
    }
}
