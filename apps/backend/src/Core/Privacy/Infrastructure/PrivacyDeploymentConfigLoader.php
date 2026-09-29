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
                throw new RuntimeException('Privacy definition list is missing: ' . $key);
            }
            $result[$key] = array_map(static fn(mixed $row): object => self::hydrate($class, $row), $data[$key]);
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
        $policies = $codes($result['retentionPolicyDefinitions'], 'code');
        $roles = $codes($result['privacyProcessingRoleDefinitions'], 'purposeCode');
        $information = $codes($result['privacyProcessingInformationDefinitions'], 'purposeCode');
        foreach ($result['processingPurposeDefinitions'] as $purpose) {
            if (!in_array($purpose->retentionPolicyCode, $policies, true)
                || array_diff($purpose->dataCategories, $categories) !== []
                || !in_array($purpose->code, $roles, true)
                || !in_array($purpose->code, $information, true)) {
                throw new RuntimeException('Privacy purpose definition has unresolved references.');
            }
        }
        foreach (['consentDefinitions', 'privacyDistributionConsentDefinitions'] as $key) {
            foreach ($result[$key] as $definition) {
                if (!in_array($definition->purposeCode, $purposes, true)) {
                    throw new RuntimeException('Consent purpose is not configured.');
                }
            }
        }
        return $result;
    }

    private static function hydrate(string $class, mixed $row): object
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new RuntimeException('Privacy definition must be an object.');
        }
        $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
        $allowed = array_map(static fn($parameter): string => $parameter->getName(), $parameters);
        if (array_diff(array_keys($row), $allowed) !== []) {
            throw new RuntimeException('Unknown privacy definition field.');
        }
        foreach (['activeFrom', 'activeUntil', 'notificationSubmittedAt', 'regulatorReviewUntil'] as $field) {
            if (isset($row[$field])) {
                if (!is_string($row[$field]) || trim($row[$field]) === '') {
                    throw new RuntimeException('Invalid privacy definition date.');
                }
                $row[$field] = new DateTimeImmutable($row[$field]);
            }
        }
        try {
            return new $class(...$row);
        } catch (Throwable $exception) {
            throw new RuntimeException('Invalid privacy definition: ' . $class, 0, $exception);
        }
    }
}
