<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;
use JsonException;
use Throwable;

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
        private int $maxStreamBytes = 1073741824,
    ) {
        if (min($maxTotalBytes, $maxSections, $maxRecordsPerSection, $maxDepth, $maxStringBytes, $maxStreamBytes) < 1) {
            throw new DomainException('Export limits must be positive.');
        }
    }

    public function export(PersonalDataExportContext $context): PersonalDataExportResult
    {
        $sections = [];
        $estimatedBytes = 0;
        foreach ($this->sections($context) as $section) {
            $encoded = $this->encode($section->toArray());
            $estimatedBytes += strlen($encoded);
            if ($estimatedBytes > $this->maxTotalBytes) {
                throw new PersonalDataExportLimitException('Export exceeds total size limit.');
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
        return new PersonalDataExportResult(
            self::SCHEMA_VERSION,
            $document['generatedAt'],
            $context->subjectPersonId,
            $sections,
            $json,
            hash('sha256', $json),
            $size,
        );
    }

    /** Spool validated pages to disk instead of retaining all records and binary chunks in memory. */
    public function exportToStream(PersonalDataExportContext $context): PersonalDataExportArtifact
    {
        // PHP creates a private temporary file and removes it when the last stream owner closes it.
        $stream = tmpfile();
        if ($stream === false) {
            throw new DomainException('Export temporary storage is unavailable.');
        }
        $size = 0;
        $hash = hash_init('sha256');
        $write = function (string $bytes) use ($stream, $hash, &$size): void {
            $length = strlen($bytes);
            if ($size + $length > $this->maxStreamBytes) {
                throw new PersonalDataExportLimitException('Export exceeds streamed size limit.');
            }
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($stream, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new DomainException('Export temporary storage write failed.');
                }
                $offset += $written;
            }
            hash_update($hash, $bytes);
            $size += $length;
        };
        $generatedAt = $context->generatedAt->format('Y-m-d\TH:i:s.uP');
        try {
            // Preserve schema v1 and the exact canonical byte order of the in-memory exporter.
            $metadata = ['generatedAt' => $generatedAt, 'schemaVersion' => self::SCHEMA_VERSION];
            $this->validate([...$metadata, 'subjectPersonId' => $context->subjectPersonId], 0);
            $write(substr($this->encode($metadata), 0, -1) . ',"sections":[');
            $first = true;
            foreach ($this->sections($context) as $section) {
                $write(($first ? '' : ',') . $this->encode($this->canonical($section->toArray())));
                $first = false;
            }
            $write('],"subjectPersonId":' . $this->encode($context->subjectPersonId) . '}');
            if (!rewind($stream)) {
                throw new DomainException('Export temporary storage rewind failed.');
            }
            return new PersonalDataExportArtifact(
                self::SCHEMA_VERSION,
                $generatedAt,
                $context->subjectPersonId,
                hash_final($hash),
                $size,
                $stream,
            );
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    /** @return iterable<PersonalDataSection> */
    private function sections(PersonalDataExportContext $context): iterable
    {
        $contributors = $this->registry->all();
        if (count($contributors) > $this->maxSections) {
            throw new PersonalDataExportLimitException('Export exceeds section limit.');
        }
        foreach ($contributors as $contributor) {
            $pages = $contributor instanceof PagedPersonalDataContributor ? $contributor->pages($context) : [$contributor->collect($context)];
            foreach ($pages as $section) {
                /** @psalm-suppress DocblockTypeContradiction */
                if (!$section instanceof PersonalDataSection || $section->contributorCode !== $contributor->code()
                    || $section->dataCategories !== $contributor->categories()
                    || count($section->records) > $this->maxRecordsPerSection) {
                    throw new PersonalDataExportLimitException('Invalid or oversized personal data section.');
                }
                $this->validate($section->toArray(), 3);
                yield $section;
            }
        }
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new DomainException('Personal data cannot be serialized.');
        }
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
