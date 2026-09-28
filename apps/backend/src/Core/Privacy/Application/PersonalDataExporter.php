<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;
use JsonException;

final readonly class PersonalDataExporter
{
    public const SCHEMA_VERSION = '1';

    public function __construct(
        private PersonalDataContributorRegistry $registry,
        private int $maxTotalBytes = 1048576,
        private int $maxSections = 32,
        private int $maxRecordsPerSection = 1000,
        private int $maxDepth = 8,
        private int $maxStringBytes = 8192,
    ) {
        if (min($maxTotalBytes, $maxSections, $maxRecordsPerSection, $maxDepth, $maxStringBytes) < 1) {
            throw new DomainException('Export limits must be positive.');
        }
    }

    public function export(PersonalDataExportContext $context): PersonalDataExportResult
    {
        $contributors = $this->registry->all();
        if (count($contributors) > $this->maxSections) {
            throw new PersonalDataExportLimitException('Export exceeds section limit.');
        }
        $sections = [];
        foreach ($contributors as $contributor) {
            $section = $contributor->collect($context);
            if ($section->contributorCode !== $contributor->code()
                || $section->dataCategories !== $contributor->categories()
                || count($section->records) > $this->maxRecordsPerSection) {
                throw new PersonalDataExportLimitException('Invalid or oversized personal data section.');
            }
            $sections[] = $section;
        }
        $document = ['schemaVersion' => self::SCHEMA_VERSION,
            'generatedAt' => $context->generatedAt->format('Y-m-d\TH:i:s.uP'),
            'subjectPersonId' => $context->subjectPersonId,
            'sections' => array_map(static fn(PersonalDataSection $section): array => $section->toArray(), $sections)];
        $this->validate($document, 0);
        try {
            $json = json_encode($this->canonical($document), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new DomainException('Personal data cannot be serialized.');
        }
        $size = strlen($json);
        if ($size > $this->maxTotalBytes) {
            throw new PersonalDataExportLimitException('Export exceeds total size limit.');
        }
        return new PersonalDataExportResult(self::SCHEMA_VERSION, $document['generatedAt'],
            $context->subjectPersonId, $sections, $json, hash('sha256', $json), $size);
    }

    private function validate(mixed $value, int $depth): void
    {
        if ($depth > $this->maxDepth) {
            throw new PersonalDataExportLimitException('Export exceeds depth limit.');
        }
        if (is_string($value)) {
            if (strlen($value) > $this->maxStringBytes) {
                throw new PersonalDataExportLimitException('Export exceeds string limit.');
            }
            return;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return;
        }
        if (!is_array($value)) {
            throw new DomainException('Export contains unsupported value.');
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && strlen($key) > 120) {
                throw new PersonalDataExportLimitException('Export field name is too long.');
            }
            $this->validate($child, $depth + 1);
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonical($child);
        }
        return $value;
    }
}
